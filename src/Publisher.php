<?php

namespace TornadoEngine\GitHub;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Publishes a module from web/modules/<folder> to GitHub, and for "packagist" also to Packagist:
 *
 *   private    a private GitHub repository
 *   public     a public repository with the topic tornado-engine-module
 *   packagist  public, and on Packagist, so other districts can install it from /modules
 *
 * It runs on the website: the GitHub and Packagist tokens never reach Claude.
 */
class Publisher
{
    public const VISIBILITIES = ['private' => 'Private', 'public' => 'Public', 'packagist' => 'Public with Packagist'];

    public function __construct(private GitHub $github) {}

    /**
     * The local modules (in web/modules) that could be published: [folder => package name].
     *
     * @return array<string, string>
     */
    public static function localModules(): array
    {
        $modules = [];
        foreach (glob(base_path('modules/*/composer.json')) ?: [] as $file) {
            $composer = json_decode((string) file_get_contents($file), true);
            if (isset($composer['name'], $composer['extra']['tornado'])) {
                $modules[basename(dirname($file))] = strtolower($composer['name']);
            }
        }

        return $modules;
    }

    public static function folderFor(string $package): ?string
    {
        return array_search(strtolower($package), self::localModules(), true) ?: null;
    }

    public static function packagistConfigured(): bool
    {
        return Setting::get('github.packagist_username') !== null && Setting::getSecret('github.packagist_token') !== null;
    }

    /**
     * @return array{url: string, version: string, packagist: ?string}
     */
    public function publish(string $folder, string $owner, string $visibility, string $version): array
    {
        if (! GitHub::connected()) {
            throw new RuntimeException(__('Connect GitHub first (Administration → GitHub).'));
        }
        if (! isset(self::localModules()[$folder])) {
            throw new RuntimeException(__('There\'s no module in web/modules/:folder.', ['folder' => $folder]));
        }
        if (! array_key_exists($visibility, self::VISIBILITIES)) {
            throw new RuntimeException(__('Choose private, public or packagist.'));
        }
        if ($visibility === 'packagist' && ! self::packagistConfigured()) {
            throw new RuntimeException(__('Add your Packagist username and API token first (Administration → GitHub).'));
        }
        $version = ltrim(trim($version), 'v');
        if (! preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            throw new RuntimeException(__('The version needs to look like 1.0.0.'));
        }

        $dir = base_path("modules/{$folder}");
        $composer = json_decode((string) file_get_contents("{$dir}/composer.json"), true);
        $repo = $this->repository($owner, $folder, $visibility, (string) ($composer['description'] ?? ''));

        $this->push($dir, $repo['clone_url'], $version);

        $packagist = null;
        if ($visibility === 'packagist') {
            $packagist = $this->packagist($owner, $folder, $repo['html_url'], strtolower($composer['name']));
        }

        return ['url' => $repo['html_url'], 'version' => $version, 'packagist' => $packagist];
    }

    /** Makes the repository (or updates its visibility), and gives public ones the module topic. */
    private function repository(string $owner, string $name, string $visibility, string $description): array
    {
        $api = $this->github->api();
        $private = $visibility === 'private';

        $existing = $api->get("/repos/{$owner}/{$name}");
        if ($existing->status() === 404) {
            $path = strcasecmp($owner, (string) GitHub::login()) === 0 ? '/user/repos' : "/orgs/{$owner}/repos";
            $repo = $api->post($path, ['name' => $name, 'description' => $description, 'private' => $private]);
            if (! $repo->successful()) {
                throw new RuntimeException(__('GitHub didn\'t make the repository: :reason', ['reason' => $repo->json('message') ?? $repo->status()]));
            }
            $repo = $repo->json();
        } else {
            $repo = $existing->throw()->json();
            if ((bool) $repo['private'] !== $private) {
                $repo = $api->patch("/repos/{$owner}/{$name}", ['private' => $private])->throw()->json();
            }
        }

        if (! $private) {
            $api->put("/repos/{$owner}/{$name}/topics", ['names' => ['tornado-engine-module']]);
        }

        return $repo;
    }

    /** Commits what's changed, tags the version, and pushes both. The token goes in a header, never the URL. */
    private function push(string $dir, string $url, string $version): void
    {
        $git = fn (array $args) => $this->git($dir, $args);

        if (! is_dir("{$dir}/.git")) {
            $git(['init', '-q', '-b', 'main']);
        }
        if (trim($git(['tag', '-l', "v{$version}"])) !== '') {
            throw new RuntimeException(__('Version :version is already published. Choose a newer one.', ['version' => $version]));
        }

        $git(['add', '-A']);
        if (trim($git(['status', '--porcelain'])) !== '') {
            $email = GitHub::login().'@users.noreply.github.com';
            $git(['-c', 'user.name='.GitHub::login(), '-c', "user.email={$email}", 'commit', '-q', '-m', "Version {$version}"]);
        }
        $git(['tag', "v{$version}"]);

        $auth = 'Authorization: Basic '.base64_encode('x-access-token:'.GitHub::token());
        $git(['-c', "http.extraHeader={$auth}", 'push', '-q', $url, 'HEAD:refs/heads/main', "refs/tags/v{$version}"]);
    }

    private function git(string $dir, array $args): string
    {
        $result = Process::path($dir)
            ->env(['GIT_CONFIG_COUNT' => '1', 'GIT_CONFIG_KEY_0' => 'safe.directory', 'GIT_CONFIG_VALUE_0' => '*', 'GIT_TERMINAL_PROMPT' => '0', 'HOME' => sys_get_temp_dir()])
            ->timeout(120)
            ->run(array_merge(['git'], $args));

        if (! $result->successful()) {
            // Never show the token: it's only ever in the push's header.
            throw new RuntimeException(__('git failed: :output', ['output' => str_replace(GitHub::token(), '***', trim($result->errorOutput() ?: $result->output()))]));
        }

        return $result->output();
    }

    /**
     * Adds Packagist's webhook (so later versions publish themselves) and submits the package.
     */
    private function packagist(string $owner, string $name, string $url, string $package): string
    {
        $username = (string) Setting::get('github.packagist_username');
        $token = (string) Setting::getSecret('github.packagist_token');

        $hooks = $this->github->api()->get("/repos/{$owner}/{$name}/hooks")->json('*.config.url') ?? [];
        if (! array_filter($hooks, fn ($hook) => str_starts_with((string) $hook, 'https://packagist.org/api/github'))) {
            $this->github->api()->post("/repos/{$owner}/{$name}/hooks", [
                'config' => ['url' => 'https://packagist.org/api/github?username='.rawurlencode($username), 'content_type' => 'json', 'secret' => $token],
                'events' => ['push'],
            ])->throw();
        }

        $response = Http::withToken("{$username}:{$token}")->acceptJson()->timeout(30)->post('https://packagist.org/api/create-package', ['repository' => $url]);
        $message = json_encode($response->json('message') ?? '');
        if (! $response->successful() && ! str_contains((string) $message, 'already exists')) {
            throw new RuntimeException(__('Packagist didn\'t take it: :reason', ['reason' => trim((string) $message, '"') ?: $response->status()]));
        }

        return "https://packagist.org/packages/{$package}";
    }

    /** The next version to suggest: one patch after the newest tag, or 0.1.0. */
    public static function nextVersion(string $folder): string
    {
        $dir = base_path("modules/{$folder}");
        $tags = is_dir("{$dir}/.git") ? Process::path($dir)->env(['GIT_CONFIG_COUNT' => '1', 'GIT_CONFIG_KEY_0' => 'safe.directory', 'GIT_CONFIG_VALUE_0' => '*'])->run(['git', 'tag', '-l', 'v*'])->output() : '';
        $versions = array_filter(array_map(fn ($tag) => ltrim(trim($tag), 'v'), explode("\n", $tags)), fn ($v) => preg_match('/^\d+\.\d+\.\d+$/', $v));
        if (! $versions) {
            return '0.1.0';
        }
        usort($versions, 'version_compare');
        [$major, $minor, $patch] = explode('.', end($versions));

        return "{$major}.{$minor}.".((int) $patch + 1);
    }
}
