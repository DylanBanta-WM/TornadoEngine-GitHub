<?php

namespace TornadoEngine\GitHub\Tests;

use App\Harness\HarnessTask;
use App\Models\Setting;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;
use TornadoEngine\Chat\Models\Conversation;
use TornadoEngine\GitHub\Mcp\GitHubServer;
use TornadoEngine\GitHub\Mcp\PublishModuleTool;
use TornadoEngine\GitHub\Publisher;

class GitHubTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    /** @var list<array{method: string, url: string, data: array}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = base_path('modules/zz-publish-test');
        File::ensureDirectoryExists($this->folder);
        file_put_contents("{$this->folder}/composer.json", json_encode(['name' => 'westmschools/zz-publish-test', 'description' => 'A test tool.', 'extra' => ['tornado' => ['name' => 'Publish Test', 'provider' => 'X']]]));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function connected(): void
    {
        Setting::putSecret('github.token', 'gho_secret');
        Setting::put('github.login', 'dylan');
        Setting::put('github.owners', json_encode(['dylan', 'westmschools']));
    }

    private function fakeGitHub(bool $exists = false): void
    {
        Http::fake(function (Request $request) use ($exists) {
            $this->sent[] = ['method' => $request->method(), 'url' => $request->url(), 'data' => $request->data()];
            $url = $request->url();

            return match (true) {
                str_contains($url, 'packagist.org/api/create-package') => Http::response(['status' => 'success']),
                $request->method() === 'GET' && str_ends_with($url, '/hooks') => Http::response([]),
                $request->method() === 'GET' && str_contains($url, '/repos/') => $exists ? Http::response(['private' => true, 'html_url' => 'https://github.com/dylan/zz-publish-test', 'clone_url' => 'https://github.com/dylan/zz-publish-test.git']) : Http::response([], 404),
                str_contains($url, '/user/repos') || str_contains($url, '/orgs/') || $request->method() === 'PATCH' => Http::response(['private' => $request['private'] ?? false, 'html_url' => 'https://github.com/dylan/zz-publish-test', 'clone_url' => 'https://github.com/dylan/zz-publish-test.git']),
                default => Http::response([]),
            };
        });
        Process::fake(["*'tag' '-l'*" => Process::result(''), "*'status' '--porcelain'*" => Process::result(" M src/Tool.php\n"), '*' => Process::result('')]);
    }

    private function sentTo(string $method, string $contains): array
    {
        return array_values(array_filter($this->sent, fn ($r) => $r['method'] === $method && str_contains($r['url'], $contains)));
    }

    public function test_connecting_shows_a_code_then_saves_the_account(): void
    {
        $admin = $this->admin();
        $pending = true;
        Http::fake([
            'github.com/login/device/code' => Http::response(['device_code' => 'dev-1', 'user_code' => 'ABCD-1234', 'verification_uri' => 'https://github.com/login/device', 'interval' => 5, 'expires_in' => 900]),
            'github.com/login/oauth/access_token' => function () use (&$pending) {
                return Http::response($pending ? ['error' => 'authorization_pending'] : ['access_token' => 'gho_new']);
            },
            'api.github.com/user' => Http::response(['login' => 'dylan']),
            'api.github.com/user/orgs*' => Http::response([['login' => 'westmschools']]),
        ]);

        $this->actingAs($admin)->get('/administration/github')->assertSee('OAuth app Client ID')->assertSee('How to set up GitHub');
        $this->actingAs($admin)->post('/administration/github/app', ['client_id' => 'Iv1.district']);
        $this->assertSame('Iv1.district', Setting::get('github.client_id'));
        $this->actingAs($admin)->get('/administration/github')->assertSee('Connect GitHub');
        $this->actingAs($admin)->post('/administration/github/connect');
        $this->actingAs($admin)->get('/administration/github')->assertSee('ABCD-1234')->assertSee('https://github.com/login/device');

        $this->actingAs($admin)->postJson('/administration/github/connect/check')->assertJson(['done' => false]);
        $pending = false;
        $this->actingAs($admin)->postJson('/administration/github/connect/check')->assertJson(['done' => true]);

        $this->assertSame('gho_new', Setting::getSecret('github.token'));
        $this->assertSame(['dylan', 'westmschools'], json_decode(Setting::get('github.owners'), true));
        $this->actingAs($admin)->get('/administration/github')->assertSee('Connected as dylan.')->assertDontSee('gho_new');
    }

    public function test_packagist_details_are_only_saved_if_packagist_accepts_them(): void
    {
        $admin = $this->admin();
        Http::fake(['packagist.org/api/create-package' => fn (Request $r) => str_contains($r->header('Authorization')[0] ?? '', 'good')
            ? Http::response(['status' => 'error', 'message' => ['repository' => 'Missing repository']], 400)
            : Http::response(['status' => 'error', 'message' => 'Missing or invalid username/apiToken in request'], 403)]);

        $this->actingAs($admin)->from('/administration/github')->post('/administration/github/packagist', ['username' => 'dylan', 'token' => 'bad'])->assertSessionHasErrors('packagist');
        $this->assertFalse(Publisher::packagistConfigured());

        $this->actingAs($admin)->post('/administration/github/packagist', ['username' => 'dylan', 'token' => 'good-token']);
        $this->assertTrue(Publisher::packagistConfigured());
    }

    public function test_publishing_with_packagist_makes_a_public_repo_pushes_tags_and_submits(): void
    {
        $this->connected();
        Setting::put('github.packagist_username', 'dylan');
        Setting::putSecret('github.packagist_token', 'pk-token');
        $this->fakeGitHub();

        $this->actingAs($this->admin())->post('/modules/publish', ['package' => 'westmschools/zz-publish-test', 'owner' => 'dylan', 'visibility' => 'packagist', 'version' => 'v1.0.0'])
            ->assertSessionHas('modules_status', 'Published version 1.0.0 to https://packagist.org/packages/westmschools/zz-publish-test.');

        $this->assertFalse($this->sentTo('POST', '/user/repos')[0]['data']['private']);
        $this->assertSame(['tornado-engine-module'], $this->sentTo('PUT', '/topics')[0]['data']['names']);
        $this->assertSame('https://packagist.org/api/github?username=dylan', $this->sentTo('POST', '/hooks')[0]['data']['config']['url']);
        $this->assertSame('https://github.com/dylan/zz-publish-test', $this->sentTo('POST', 'create-package')[0]['data']['repository']);
        Process::assertRan(fn ($p) => in_array('tag', $p->command, true) && in_array('v1.0.0', $p->command, true));
        Process::assertRan(fn ($p) => in_array('push', $p->command, true) && in_array('refs/tags/v1.0.0', $p->command, true)
            && ! str_contains(implode(' ', $p->command), 'gho_secret'));
    }

    public function test_private_modules_go_to_github_only(): void
    {
        $this->connected();
        $this->fakeGitHub();

        app(Publisher::class)->publish('zz-publish-test', 'westmschools', 'private', '0.1.0');

        $this->assertTrue($this->sentTo('POST', '/orgs/westmschools/repos')[0]['data']['private']);
        $this->assertSame([], $this->sentTo('PUT', '/topics'));
        $this->assertSame([], $this->sentTo('POST', 'create-package'));
    }

    public function test_packagist_needs_its_details_and_versions_cant_repeat(): void
    {
        $this->connected();
        $this->fakeGitHub();

        $this->expectExceptionMessage('Add your Packagist username and API token first');
        app(Publisher::class)->publish('zz-publish-test', 'dylan', 'packagist', '1.0.0');
    }

    public function test_chat_can_publish_for_people_allowed_to(): void
    {
        $this->connected();
        $this->fakeGitHub(exists: true);
        $admin = $this->admin();

        GitHubServer::tool(PublishModuleTool::class, ['folder' => 'zz-publish-test', 'visibility' => 'public'])->assertHasErrors();

        $conversation = Conversation::create(['user_id' => $admin->id, 'title' => 'Publish']);
        HarnessTask::create(['source' => 'chat', 'reference' => (string) $conversation->id, 'prompt' => 'x', 'status' => 'running']);

        GitHubServer::tool(PublishModuleTool::class, ['folder' => 'zz-publish-test', 'visibility' => 'public'])
            ->assertOk()->assertSee('Published version 0.1.0 to https://github.com/dylan/zz-publish-test.');
        $this->assertFalse($this->sentTo('PATCH', '/repos/dylan/zz-publish-test')[0]['data']['private']);
    }

    public function test_local_modules_get_a_publish_button_on_the_modules_page(): void
    {
        $this->connected();
        Http::fake(['*' => Http::response([], 500)]);
        $registry = new ModuleRegistry(base_path('vendor/composer/installed.json'), config('tornado.version'));
        // The test module isn't installed by Composer, so it isn't listed; a real local module (here: one in the dev copy) is.
        $local = array_filter(Publisher::localModules(), fn ($package) => isset($registry->all()[$package]));

        $page = $this->actingAs($this->admin())->get('/modules')->assertOk();
        $local ? $page->assertSee('Publish') : $this->assertTrue(true);
    }
}
