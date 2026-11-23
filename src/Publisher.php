<?php

namespace TornadoEngine\GitHub;

use App\Models\Setting;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Http\Client\ConnectionException;
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

    /** A Packagist API token, and a connected GitHub account: Packagist accounts sign up with GitHub, so its login is the username. */
    public static function packagistConfigured(): bool
    {
        return GitHub::login() !== null && Setting::getSecret('github.packagist_token') !== null;
    }

    /**
     * Whether Packagist accepts this username and API token (asks for a package without a repository).
     *
     * @throws ConnectionException when Packagist doesn't answer
     */
    public static function packagistAccepts(string $username, string $token): bool
    {
        $response = Http::withToken("{$username}:{$token}")->acceptJson()->timeout(15)->post('https://packagist.org/api/create-package', ['repository' => '']);

        return $response->status() !== 403 && ! str_contains(strtolower(json_encode($response->json()) ?: ''), 'username/apitoken');
    }

    /**
     * @return array{url: string, version: string, packagist: ?string}
     */
    public function publish(string $folder, string $owner, string $visibility, string $version, ?string $message = null): array
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
        $version = ltrim(trim($version), 'v');
        if (! preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            throw new RuntimeException(__('The version needs to look like 1.0.0.'));
        }

        $dir = base_path("modules/{$folder}");
        $composer = json_decode((string) file_get_contents("{$dir}/composer.json"), true);
        $package = strtolower((string) ($composer['name'] ?? ''));
        $name = $folder;
        $topics = ['tornado-engine-module'];

        // Official modules (tornado-engine/…) go to the official account, public on Packagist, named and
        // licensed like the others. Only that account can create them, so other districts can't.
        if (self::official($package)) {
            $owner = (string) config('tornado.official_owner');
            $visibility = 'packagist';
            $name = self::officialRepository($folder);
            $topics[] = 'tornado-engine';
            if (! is_file("{$dir}/LICENSE")) {
                file_put_contents("{$dir}/LICENSE", self::license());
            }
        }
        // A working copy of a module this site maintains goes back to the repository it was cloned from.
        if ($origin = self::origin($dir)) {
            [$owner, $name] = [$origin['owner'], $origin['name']];
            // Published as it already is: private stays private, a package on Packagist stays there.
            $existing = $this->github->api()->get("/repos/{$owner}/{$name}");
            if ($existing->successful()) {
                $visibility = $existing->json('private') ? 'private'
                    : (Http::timeout(15)->get("https://repo.packagist.org/p2/{$package}.json")->successful() ? 'packagist' : 'public');
            }
        }
        if ($visibility === 'packagist') {
            if (! self::packagistConfigured()) {
                throw new RuntimeException(__('Add your Packagist API token first (Administration → GitHub).'));
            }
            $this->checkVendor($package);
        }

        $repo = $this->repository($owner, $name, $visibility, (string) ($composer['description'] ?? ''), $topics);

        $this->push($dir, $repo['clone_url'], $version, $message);

        $packagist = null;
        if ($visibility === 'packagist') {
            $packagist = $this->packagist($owner, $name, $repo['html_url'], $package);
        }

        return ['url' => $repo['html_url'], 'version' => $version, 'packagist' => $packagist];
    }

    /** Makes the repository (or updates its visibility), and gives public ones the module topic. */
    /** The Composer vendor of official Tornado Engine modules. */
    public const OFFICIAL_VENDOR = 'tornado-engine';

    public static function official(string $package): bool
    {
        return str_starts_with(strtolower($package), self::OFFICIAL_VENDOR.'/');
    }

    /** access-google becomes TornadoEngine-Access-Google, like the other official repositories. */
    public static function officialRepository(string $folder): string
    {
        return 'TornadoEngine-'.implode('-', array_map('ucfirst', explode('-', $folder)));
    }

    private static function license(): string
    {
        return "MIT License\n\nCopyright (c) ".date('Y')." West Muskingum Local Schools\n\n"
            ."Permission is hereby granted, free of charge, to any person obtaining a copy\nof this software and associated documentation files (the \"Software\"), to deal\n"
            ."in the Software without restriction, including without limitation the rights\nto use, copy, modify, merge, publish, distribute, sublicense, and/or sell\n"
            ."copies of the Software, and to permit persons to whom the Software is\nfurnished to do so, subject to the following conditions:\n\n"
            ."The above copyright notice and this permission notice shall be included in all\ncopies or substantial portions of the Software.\n\n"
            ."THE SOFTWARE IS PROVIDED \"AS IS\", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR\nIMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,\n"
            ."FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE\nAUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER\n"
            ."LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,\nOUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE\nSOFTWARE.\n";
    }

    /**
     * Before anything is created: a Packagist vendor belongs to whoever registered it, so publishing
     * under someone else's (tornado-engine, say) can only fail. Says so up front.
     */
    private function checkVendor(string $package): void
    {
        $vendor = strtok($package, '/');
        $login = strtolower((string) GitHub::login());
        $names = Http::acceptJson()->timeout(15)->get('https://packagist.org/packages/list.json', ['vendor' => $vendor])->json('packageNames') ?? [];
        if ($names === []) {
            return;
        }
        $maintainers = Http::acceptJson()->timeout(15)->get('https://packagist.org/packages/'.$names[0].'.json')->json('package.maintainers') ?? [];
        if ($maintainers !== [] && ! in_array($login, array_map(fn ($m) => strtolower((string) ($m['name'] ?? '')), $maintainers), true)) {
            throw new RuntimeException(__('The Packagist vendor :vendor belongs to another account, so :login can\'t publish under it. Use your district\'s vendor name in the module\'s composer.json.', ['vendor' => $vendor, 'login' => GitHub::login()]));
        }
    }

    /** @param  list<string>  $topics */
    private function repository(string $owner, string $name, string $visibility, string $description, array $topics = ['tornado-engine-module']): array
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
            $api->put("/repos/{$owner}/{$name}/topics", ['names' => $topics]);
        }

        return $repo;
    }

    /** Commits what's changed, tags the version, and pushes both. The token goes in a header, never the URL. */
    private function push(string $dir, string $url, string $version, ?string $message = null): void
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
            $git(['-c', 'user.name='.GitHub::login(), '-c', "user.email={$email}", 'commit', '-q', '-m', trim((string) $message) !== '' ? "Version {$version}: ".trim((string) $message) : "Version {$version}"]);
        }

        $auth = 'Authorization: Basic '.base64_encode('x-access-token:'.GitHub::token());
        // A working copy of a module this site maintains: its repository may have moved on since it was cloned
        // (a release from elsewhere). These changes go on top of that; if they clash, nothing is pushed.
        if (self::origin($dir)) {
            $git(['-c', "http.extraHeader={$auth}", 'fetch', '-q', '--tags', 'origin']);
            if (trim($git(['tag', '-l', "v{$version}"])) !== '') {
                throw new RuntimeException(__('Version :version is already published. Choose a newer one.', ['version' => $version]));
            }
            $rebase = self::gitRun($dir, ['-c', 'user.name='.GitHub::login(), '-c', 'user.email='.GitHub::login().'@users.noreply.github.com', 'rebase', 'origin/main']);
            if (! $rebase->successful()) {
                self::gitRun($dir, ['rebase', '--abort']);
                throw new RuntimeException(__('The repository has newer changes that clash with these, so nothing was published. Bring them together in web/modules (Chat can help), then publish again.'));
            }
        }
        $git(['tag', "v{$version}"]);

        $git(['-c', "http.extraHeader={$auth}", 'push', '-q', $url, 'HEAD:refs/heads/main', "refs/tags/v{$version}"]);
    }

    /** Runs git in a module's folder (which the website's own user may not own), without throwing. */
    public static function gitRun(string $dir, array $args, int $timeout = 120): ProcessResult
    {
        return Process::path($dir)
            ->env(['GIT_CONFIG_COUNT' => '1', 'GIT_CONFIG_KEY_0' => 'safe.directory', 'GIT_CONFIG_VALUE_0' => '*', 'GIT_TERMINAL_PROMPT' => '0', 'HOME' => sys_get_temp_dir()])
            ->timeout($timeout)
            ->run(array_merge(['git'], $args));
    }

    /** The GitHub repository a working copy was cloned from, if it was. */
    public static function origin(string $dir): ?array
    {
        $url = is_dir("{$dir}/.git") ? trim((string) self::gitRun($dir, ['remote', 'get-url', 'origin'])->output()) : '';

        return preg_match('#github\.com[/:]([^/]+)/([^/]+?)(?:\.git)?$#', $url, $m) ? ['owner' => $m[1], 'name' => $m[2]] : null;
    }

    private function git(string $dir, array $args): string
    {
        $result = self::gitRun($dir, $args);

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
        $username = (string) GitHub::login();
        $token = (string) Setting::getSecret('github.packagist_token');

        // The webhook tells Packagist about every push from now on. Its address names the Packagist user (the GitHub
        // login); one made without it (by GitHub module 0.2) gets 404 from Packagist, so it's corrected.
        $config = ['url' => 'https://packagist.org/api/github?username='.rawurlencode($username), 'content_type' => 'json', 'secret' => $token, 'insecure_ssl' => '0'];
        $existing = collect($this->github->api()->get("/repos/{$owner}/{$name}/hooks")->json() ?? [])
            ->first(fn ($hook) => str_starts_with((string) ($hook['config']['url'] ?? ''), 'https://packagist.org/api/github'));
        if ($existing) {
            $hook = $existing['id'];
            if (($existing['config']['url'] ?? '') !== $config['url']) {
                $this->github->api()->patch("/repos/{$owner}/{$name}/hooks/{$hook}", ['config' => $config, 'active' => true])->throw();
            }
        } else {
            $hook = $this->github->api()->post("/repos/{$owner}/{$name}/hooks", ['config' => $config, 'events' => ['push']])->throw()->json('id');
        }

        $response = Http::withToken("{$username}:{$token}")->acceptJson()->timeout(30)->post('https://packagist.org/api/create-package', ['repository' => $url]);
        $message = json_encode($response->json('message') ?? '');
        if (! $response->successful() && ! str_contains((string) $message, 'already exists')) {
            throw new RuntimeException(__('Packagist didn\'t take it: :reason', ['reason' => trim((string) $message, '"') ?: $response->status()]));
        }

        // The code was pushed before the webhook existed, so Packagist hasn't heard from GitHub yet and marks the
        // package "Not Auto-Updated". GitHub's hook test sends the latest push through the webhook (signed, like any
        // push), which reads the new version and marks it auto-updated. Never Packagist's update-package API: that
        // marks it "Legacy Auto-Update" instead.
        if ($hook) {
            // An empty JSON object: GitHub refuses a body of [].
            $this->github->api()->withBody('{}', 'application/json')->post("/repos/{$owner}/{$name}/hooks/{$hook}/tests");
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
