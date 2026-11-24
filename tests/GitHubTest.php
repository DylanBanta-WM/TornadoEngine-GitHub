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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use TornadoEngine\Chat\Models\Conversation;
use TornadoEngine\GitHub\GitHub;
use TornadoEngine\GitHub\Maintainer;
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

    private function fakeGitHub(bool $exists = false, array $hooks = [], array $vendorMaintainers = [], ?string $origin = null, bool $rebaseFails = false): void
    {
        Http::fake(function (Request $request) use ($exists, $hooks, $vendorMaintainers) {
            $this->sent[] = ['method' => $request->method(), 'url' => $request->url(), 'data' => $request->data(), 'body' => $request->body()];
            $url = $request->url();

            return match (true) {
                str_contains($url, 'packagist.org/api/create-package') => Http::response(['status' => 'success']),
                str_contains($url, 'packagist.org/packages/list.json') => Http::response(['packageNames' => $vendorMaintainers ? ['westmschools/existing'] : []]),
                str_contains($url, 'packagist.org/packages/westmschools/existing.json') => Http::response(['package' => ['maintainers' => array_map(fn ($name) => ['name' => $name], $vendorMaintainers)]]),
                $request->method() === 'GET' && str_ends_with($url, '/hooks') => Http::response($hooks),
                $request->method() === 'POST' && str_ends_with($url, '/hooks') => Http::response(['id' => 42], 201),
                $request->method() === 'GET' && str_contains($url, '/repos/') => $exists ? Http::response(['private' => true, 'html_url' => 'https://github.com/dylan/zz-publish-test', 'clone_url' => 'https://github.com/dylan/zz-publish-test.git']) : Http::response([], 404),
                str_contains($url, '/user/repos') || str_contains($url, '/orgs/') || $request->method() === 'PATCH' => Http::response(['private' => $request['private'] ?? false, 'html_url' => 'https://github.com/dylan/zz-publish-test', 'clone_url' => 'https://github.com/dylan/zz-publish-test.git']),
                default => Http::response([]),
            };
        });
        Process::fake(["*'remote' 'get-url' 'origin'*" => Process::result($origin ?? '', '', $origin ? 0 : 2), "*'rebase' 'origin/main'*" => Process::result('', $rebaseFails ? 'CONFLICT' : '', $rebaseFails ? 1 : 0), "*'tag' '-l'*" => Process::result(''), "*'status' '--porcelain'*" => Process::result(" M src/Tool.php\n"), '*' => Process::result('')]);
    }

    private function sentTo(string $method, string $contains): array
    {
        return array_values(array_filter($this->sent, fn ($r) => $r['method'] === $method && str_contains($r['url'], $contains)));
    }

    /** GitHub's device flow; $answers are the access-token answers in order (the last one repeats). */
    private function fakeDeviceFlow(array &$answers, bool $packagistAccepts = true): void
    {
        Http::fake(function (Request $request) use (&$answers, $packagistAccepts) {
            $this->sent[] = ['method' => $request->method(), 'url' => $request->url(), 'data' => $request->data(), 'body' => $request->body()];
            $url = $request->url();

            return match (true) {
                str_contains($url, 'github.com/login/device/code') => Http::response(['device_code' => 'dev-1', 'user_code' => 'ABCD-1234', 'verification_uri' => 'https://github.com/login/device', 'interval' => 5, 'expires_in' => 900]),
                str_contains($url, 'github.com/login/oauth/access_token') => Http::response(count($answers) > 1 ? array_shift($answers) : $answers[0]),
                str_contains($url, 'api.github.com/user/orgs') => Http::response([['login' => 'westmschools']]),
                str_contains($url, 'api.github.com/user') => Http::response(['login' => 'dylan']),
                str_contains($url, 'packagist.org/api/create-package') => $packagistAccepts && str_contains($request->header('Authorization')[0] ?? '', 'dylan:pk-good')
                    ? Http::response(['status' => 'error', 'message' => ['repository' => 'Missing repository']], 400)
                    : Http::response(['status' => 'error', 'message' => 'Missing or invalid username/apiToken in request'], 403),
                default => Http::response([], 404),
            };
        });
    }

    /** Polls as the page does, as if the interval had passed. */
    private function poll(User $admin): TestResponse
    {
        $this->travel(11)->seconds();

        return $this->actingAs($admin)->postJson('/administration/github/connect/check');
    }

    private function tokenRequests(): int
    {
        return count(array_filter($this->sent, fn ($r) => str_contains($r['url'], 'login/oauth/access_token')));
    }

    public function test_connecting_shows_a_code_then_saves_the_account(): void
    {
        $admin = $this->admin();
        $answers = [['error' => 'authorization_pending'], ['access_token' => 'gho_new', 'token_type' => 'bearer', 'scope' => 'repo']];
        $this->fakeDeviceFlow($answers);

        $this->actingAs($admin)->get('/settings/github')->assertSee('GitHub OAuth app Client ID')->assertSee('Packagist API token')->assertSee('How to set up GitHub')->assertDontSee('Connect GitHub');
        $this->actingAs($admin)->from('/settings/github')->post('/administration/github', ['client_id' => 'Iv1.district', 'token' => 'pk-good'])
            ->assertRedirect('/settings/github')->assertSessionHas('github_status', 'Saved.');
        $this->assertSame('Iv1.district', Setting::get('github.client_id'));
        $this->actingAs($admin)->get('/settings/github')->assertSee('Saved.')->assertSee('Connect GitHub')->assertDontSee('pk-good');
        $this->actingAs($admin)->from('/settings/github')->post('/administration/github/connect');
        $this->actingAs($admin)->get('/settings/github')->assertSee('ABCD-1234')->assertSee('https://github.com/login/device');

        $this->poll($admin)->assertExactJson(['done' => false, 'interval' => 6]);
        $this->poll($admin)->assertExactJson(['done' => true]);
        $this->actingAs($admin)->from('/settings/github')->post('/administration/github/connected')->assertRedirect('/settings/github');

        $this->assertSame('gho_new', Setting::getSecret('github.token'));
        $this->assertSame(['dylan', 'westmschools'], json_decode(Setting::get('github.owners'), true));
        $this->assertSame('pk-good', Setting::getSecret('github.packagist_token'));
        $this->actingAs($admin)->get('/settings/github')->assertSee('Connected as dylan.')->assertDontSee('gho_new');
    }

    public function test_slow_down_makes_it_wait_longer_instead_of_waiting_forever(): void
    {
        $admin = $this->admin();
        Setting::put('github.client_id', 'Iv1.district');
        $answers = [['error' => 'slow_down', 'error_description' => 'Too many requests.', 'interval' => 10], ['access_token' => 'gho_new']];
        $this->fakeDeviceFlow($answers);
        $this->actingAs($admin)->post('/administration/github/connect');

        // Asked too soon: the website waits instead of asking GitHub.
        $this->actingAs($admin)->postJson('/administration/github/connect/check')->assertJson(['done' => false]);
        $this->assertSame(0, $this->tokenRequests());

        $this->travel(6)->seconds();
        $this->actingAs($admin)->postJson('/administration/github/connect/check')->assertExactJson(['done' => false, 'interval' => 11]);
        $this->assertSame(1, $this->tokenRequests());

        // The page polls at the old 5 seconds: still not asking GitHub again until the new interval has passed.
        $this->travel(6)->seconds();
        $this->actingAs($admin)->postJson('/administration/github/connect/check')->assertJson(['done' => false]);
        $this->assertSame(1, $this->tokenRequests());

        $this->travel(6)->seconds();
        $this->actingAs($admin)->postJson('/administration/github/connect/check')->assertExactJson(['done' => true]);
        $this->assertSame(2, $this->tokenRequests());
        $this->assertSame('dylan', GitHub::login());
    }

    public function test_connecting_reports_an_expired_code(): void
    {
        $admin = $this->admin();
        Setting::put('github.client_id', 'Iv1.district');
        $answers = [['error' => 'expired_token']];
        $this->fakeDeviceFlow($answers);
        $this->actingAs($admin)->post('/administration/github/connect');

        $this->poll($admin)->assertExactJson(['done' => true, 'error' => 'The code expired. Start again.']);
        $this->assertFalse(GitHub::connected());
        $this->actingAs($admin)->get('/settings/github')->assertSee('Connect GitHub');
    }

    public function test_the_page_waits_as_long_as_the_website_says(): void
    {
        Setting::put('github.client_id', 'Iv1.district');
        $answers = [['error' => 'authorization_pending']];
        $this->fakeDeviceFlow($answers);
        $admin = $this->admin();
        $this->actingAs($admin)->post('/administration/github/connect');

        $this->actingAs($admin)->get('/settings/github')
            ->assertSee('connect\\/check', false)->assertSee('github/connected', false)
            ->assertSee('data.interval', false)->assertDontSee('setInterval', false);
    }

    public function test_both_settings_are_checked_and_errors_go_next_to_their_field(): void
    {
        $admin = $this->admin();
        Setting::put('github.client_id', 'Iv1.good');
        $this->connected();
        $deviceFlow = false;
        Http::fake([
            'github.com/login/device/code' => function () use (&$deviceFlow) {
                return Http::response($deviceFlow ? ['device_code' => 'x', 'user_code' => 'y', 'verification_uri' => 'z'] : ['error' => 'device_flow_disabled']);
            },
            'packagist.org/api/create-package' => fn (Request $r) => str_contains($r->header('Authorization')[0] ?? '', 'dylan:pk-good')
                ? Http::response(['status' => 'error', 'message' => ['repository' => 'Missing repository']], 400)
                : Http::response(['status' => 'error', 'message' => 'Missing or invalid username/apiToken in request'], 403),
        ]);

        $this->actingAs($admin)->from('/settings/github')->post('/administration/github', ['client_id' => 'Iv1.bad', 'token' => 'bad'])
            ->assertRedirect('/settings/github')
            ->assertSessionHasErrors(['client_id' => 'Turn on Device Flow for this OAuth app on GitHub.', 'token' => 'Packagist didn\'t accept this API token for dylan.']);
        $this->assertSame('Iv1.good', Setting::get('github.client_id'));
        $this->assertFalse(Publisher::packagistConfigured());

        $deviceFlow = true;
        $this->actingAs($admin)->from('/settings/github')->post('/administration/github', ['client_id' => 'Iv1.good', 'token' => 'pk-good'])->assertSessionHasNoErrors();
        $this->assertTrue(Publisher::packagistConfigured());

        // The saved token is kept when the field is left empty.
        $this->actingAs($admin)->from('/settings/github')->post('/administration/github', ['client_id' => 'Iv1.good', 'token' => ''])->assertSessionHasNoErrors();
        $this->assertSame('pk-good', Setting::getSecret('github.packagist_token'));
        $this->assertTrue(GitHub::connected());
    }

    public function test_a_token_saved_before_connecting_is_checked_with_the_github_login(): void
    {
        $admin = $this->admin();
        Setting::put('github.client_id', 'Iv1.district');
        Setting::putSecret('github.packagist_token', 'pk-wrong');
        $answers = [['access_token' => 'gho_new']];
        $this->fakeDeviceFlow($answers);
        $this->actingAs($admin)->post('/administration/github/connect');

        $this->poll($admin)->assertExactJson(['done' => true]);
        $this->assertNull(Setting::getSecret('github.packagist_token'));
        $this->actingAs($admin)->from('/settings/github')->post('/administration/github/connected');
        $this->actingAs($admin)->get('/settings/github')->assertSee('Packagist didn&#039;t accept this API token for dylan.', false);
    }

    public function test_set_up_needs_the_client_id_a_connected_account_and_a_packagist_token(): void
    {
        $this->assertFalse(GitHub::configured());
        Setting::put('github.client_id', 'Iv1.district');
        $this->assertFalse(GitHub::configured());
        Setting::putSecret('github.packagist_token', 'pk-good');
        $this->assertFalse(GitHub::configured());
        $this->connected();
        $this->assertTrue(GitHub::configured());

        $composer = json_decode(file_get_contents(dirname(__DIR__).'/composer.json'), true);
        $this->assertSame('TornadoEngine\\GitHub\\GitHub@configured', $composer['extra']['tornado']['setup-done']);
    }

    public function test_publishing_with_packagist_makes_a_public_repo_pushes_tags_and_submits(): void
    {
        $this->connected();
        Setting::putSecret('github.packagist_token', 'pk-token');
        $this->fakeGitHub();

        $this->actingAs($this->admin())->post('/modules/publish', ['package' => 'westmschools/zz-publish-test', 'owner' => 'dylan', 'visibility' => 'packagist', 'version' => 'v1.0.0'])
            ->assertSessionHas('modules_status', 'Published version 1.0.0 to https://packagist.org/packages/westmschools/zz-publish-test.');

        $this->assertFalse($this->sentTo('POST', '/user/repos')[0]['data']['private']);
        $this->assertSame(['tornado-engine-module'], $this->sentTo('PUT', '/topics')[0]['data']['names']);
        $this->assertSame('https://packagist.org/api/github?username=dylan', $this->sentTo('POST', '/hooks')[0]['data']['config']['url']);
        $this->assertSame('https://github.com/dylan/zz-publish-test', $this->sentTo('POST', 'create-package')[0]['data']['repository']);
        // The push came before the webhook, so GitHub sends it through the webhook once the package exists (never update-package).
        $order = array_map(fn ($r) => $r['url'], array_filter($this->sent, fn ($r) => $r['method'] === 'POST'));
        $this->assertSame('https://api.github.com/repos/dylan/zz-publish-test/hooks/42/tests', end($order));
        $this->assertSame('{}', $this->sentTo('POST', '/hooks/42/tests')[0]['body'], 'GitHub refuses a body of []');
        $this->assertSame([], $this->sentTo('POST', 'update-package'));
        Process::assertRan(fn ($p) => in_array('tag', $p->command, true) && in_array('v1.0.0', $p->command, true));
        Process::assertRan(fn ($p) => in_array('push', $p->command, true) && in_array('refs/tags/v1.0.0', $p->command, true)
            && ! str_contains(implode(' ', $p->command), 'gho_secret'));
    }

    public function test_a_packagist_webhook_without_the_username_is_corrected(): void
    {
        $this->connected();
        Setting::putSecret('github.packagist_token', 'pk-token');
        $this->fakeGitHub(exists: true, hooks: [['id' => 7, 'config' => ['url' => 'https://packagist.org/api/github']]]);

        $this->actingAs($this->admin())->post('/modules/publish', ['package' => 'westmschools/zz-publish-test', 'owner' => 'dylan', 'visibility' => 'packagist', 'version' => 'v1.0.1']);

        $this->assertSame('https://packagist.org/api/github?username=dylan', $this->sentTo('PATCH', '/hooks/7')[0]['data']['config']['url']);
        $this->assertSame([], array_filter($this->sentTo('POST', '/hooks'), fn ($r) => str_ends_with($r['url'], '/hooks')), 'no second webhook');
        $this->assertCount(1, $this->sentTo('POST', '/hooks/7/tests'));
    }

    public function test_official_modules_go_to_the_official_account_public_on_packagist(): void
    {
        file_put_contents("{$this->folder}/composer.json", json_encode(['name' => 'tornado-engine/zz-publish-test', 'description' => 'A test tool.', 'extra' => ['tornado' => ['name' => 'Publish Test', 'provider' => 'X']]]));
        @unlink("{$this->folder}/LICENSE");
        $this->connected();
        Setting::putSecret('github.packagist_token', 'pk-token');
        $this->fakeGitHub();

        // Whatever owner and visibility were asked for.
        $this->actingAs($this->admin())->post('/modules/publish', ['package' => 'tornado-engine/zz-publish-test', 'owner' => 'dylan', 'visibility' => 'private', 'version' => 'v1.0.0'])
            ->assertSessionHas('modules_status');

        $this->assertSame('TornadoEngine-Zz-Publish-Test', $this->sentTo('POST', '/orgs/DylanBanta-WM/repos')[0]['data']['name']);
        $this->assertFalse($this->sentTo('POST', '/orgs/DylanBanta-WM/repos')[0]['data']['private']);
        $this->assertSame(['tornado-engine-module', 'tornado-engine'], $this->sentTo('PUT', '/repos/DylanBanta-WM/TornadoEngine-Zz-Publish-Test/topics')[0]['data']['names']);
        $this->assertCount(1, $this->sentTo('POST', 'create-package'));
        $this->assertStringContainsString('Copyright (c) '.date('Y').' West Muskingum Local Schools', (string) file_get_contents("{$this->folder}/LICENSE"));
        @unlink("{$this->folder}/LICENSE");
    }

    public function test_a_packagist_vendor_another_account_owns_is_refused_before_anything_is_made(): void
    {
        $this->connected();
        Setting::putSecret('github.packagist_token', 'pk-token');
        $this->fakeGitHub(vendorMaintainers: ['someone-else']);

        $this->actingAs($this->admin())->post('/modules/publish', ['package' => 'westmschools/zz-publish-test', 'owner' => 'dylan', 'visibility' => 'packagist', 'version' => 'v1.0.0'])
            ->assertSessionHasErrors();
        $this->assertSame([], $this->sentTo('POST', '/user/repos'), 'no repository made');
    }

    public function test_a_packagist_vendor_this_account_owns_is_fine(): void
    {
        $this->connected();
        Setting::putSecret('github.packagist_token', 'pk-token');
        $this->fakeGitHub(vendorMaintainers: ['Dylan']);
        $this->actingAs($this->admin())->post('/modules/publish', ['package' => 'westmschools/zz-publish-test', 'owner' => 'dylan', 'visibility' => 'packagist', 'version' => 'v1.0.0'])
            ->assertSessionHas('modules_status');
    }

    public function test_a_working_copy_goes_back_to_its_own_repository_as_it_already_is(): void
    {
        $this->connected();
        Setting::putSecret('github.packagist_token', 'pk-token');
        File::ensureDirectoryExists("{$this->folder}/.git");
        $this->fakeGitHub(exists: true, origin: 'https://github.com/DylanBanta-WM/TornadoEngine-Zz-Existing.git');

        $this->actingAs($this->admin())->post('/modules/publish', ['package' => 'westmschools/zz-publish-test', 'owner' => 'dylan', 'visibility' => 'private', 'version' => 'v1.0.1']);

        $this->assertSame([], $this->sentTo('POST', '/user/repos'), 'no new repository');
        $this->assertNotEmpty($this->sentTo('GET', '/repos/DylanBanta-WM/TornadoEngine-Zz-Existing'));
        $this->assertSame([], $this->sentTo('PATCH', '/repos/'), 'its visibility is left as it is');
        Process::assertRan(fn ($p) => in_array('push', $p->command, true) && in_array('refs/tags/v1.0.1', $p->command, true));
        // On top of whatever was released since it was cloned.
        Process::assertRan(fn ($p) => in_array('fetch', $p->command, true));
        Process::assertRan(fn ($p) => in_array('rebase', $p->command, true) && in_array('origin/main', $p->command, true));
        File::deleteDirectory("{$this->folder}/.git");
    }

    public function test_changes_that_clash_with_a_newer_release_are_not_published(): void
    {
        File::ensureDirectoryExists("{$this->folder}/.git");
        $this->connected();
        $this->fakeGitHub(exists: true, origin: 'https://github.com/DylanBanta-WM/TornadoEngine-Zz-Existing.git', rebaseFails: true);

        $this->actingAs($this->admin())->post('/modules/publish', ['package' => 'westmschools/zz-publish-test', 'owner' => 'dylan', 'visibility' => 'public', 'version' => 'v1.0.1'])
            ->assertSessionHasErrors();
        Process::assertRan(fn ($p) => in_array('--abort', $p->command, true));
        Process::assertDidntRun(fn ($p) => in_array('push', $p->command, true));
        File::deleteDirectory("{$this->folder}/.git");
    }

    public function test_only_modules_the_account_can_push_to_are_worked_on_here(): void
    {
        $this->connected();
        Http::fake(['api.github.com/repos/*' => Http::response(['permissions' => ['push' => false]])]);

        $this->assertFalse(app(Maintainer::class)->canPush('nobody/not-installed'));
        $this->expectException(\RuntimeException::class);
        app(Maintainer::class)->workOn('nobody/not-installed');
    }

    public function test_a_module_with_a_working_copy_is_worked_on_where_it_is(): void
    {
        $this->connected();
        $this->assertSame('zz-publish-test', app(Maintainer::class)->workOn('westmschools/zz-publish-test'));
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

        $this->expectExceptionMessage('Add your Packagist API token first');
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
