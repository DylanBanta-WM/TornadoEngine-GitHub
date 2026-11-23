<?php

namespace TornadoEngine\GitHub;

use App\Updates\UpdateJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Modules this site maintains: any installed module whose GitHub repository the connected account can push to
 * (official modules on the official account's site, a district's own modules on its site, shared repositories).
 * Claude works on those directly: the repository is cloned into web/modules, linked in place of the installed
 * copy, changed there, and published as a new version. Modules this site can't push to are customized instead.
 */
class Maintainer
{
    public function __construct(private GitHub $github) {}

    /** @return array{owner: string, name: string}|null the module's GitHub repository, from what Composer installed */
    public static function repository(string $package): ?array
    {
        $installed = json_decode((string) @file_get_contents(base_path('vendor/composer/installed.json')), true);
        foreach ($installed['packages'] ?? $installed ?? [] as $entry) {
            if (strtolower((string) ($entry['name'] ?? '')) !== strtolower($package)) {
                continue;
            }
            foreach ([$entry['source']['url'] ?? null, $entry['support']['source'] ?? null, $entry['homepage'] ?? null] as $url) {
                if (is_string($url) && preg_match('#github\.com[/:]([^/]+)/([^/]+?)(?:\.git)?(?:/.*)?$#', $url, $m)) {
                    return ['owner' => $m[1], 'name' => $m[2]];
                }
            }
        }
        // A working copy in web/modules says where it came from.
        $folder = Publisher::folderFor($package);

        return $folder ? Publisher::origin(base_path("modules/{$folder}")) : null;
    }

    /** Whether the connected account can push to the module's repository (so this site maintains it). */
    public function canPush(string $package): bool
    {
        if (! GitHub::connected() || ! ($repo = self::repository($package))) {
            return false;
        }

        return Cache::remember('github.push.'.strtolower(GitHub::login().'|'.$repo['owner'].'/'.$repo['name']), now()->addMinutes(10), function () use ($repo) {
            $response = $this->github->api()->get("/repos/{$repo['owner']}/{$repo['name']}");

            return $response->successful() && (bool) $response->json('permissions.push');
        });
    }

    /**
     * Clones the module's repository into web/modules/<folder> and queues linking it in place of the installed copy.
     *
     * @return string the folder in web/modules
     */
    public function workOn(string $package): string
    {
        if (! GitHub::connected()) {
            throw new RuntimeException(__('Connect GitHub first (Administration → GitHub).'));
        }
        if ($folder = Publisher::folderFor($package)) {
            return $folder;
        }
        if (! ($repo = self::repository($package)) || ! $this->canPush($package)) {
            throw new RuntimeException(__(':account can\'t push to :package\'s repository, so this site doesn\'t maintain it.', ['account' => GitHub::login(), 'package' => $package]));
        }

        $folder = substr($package, strpos($package, '/') + 1);
        $dir = base_path("modules/{$folder}");
        if (file_exists($dir)) {
            throw new RuntimeException(__('web/modules/:folder is already used by something else.', ['folder' => $folder]));
        }
        File::ensureDirectoryExists(base_path('modules'));

        $auth = 'Authorization: Basic '.base64_encode('x-access-token:'.GitHub::token());
        $result = Publisher::gitRun(base_path('modules'), ['-c', "http.extraHeader={$auth}", 'clone', '-q', "https://github.com/{$repo['owner']}/{$repo['name']}.git", $dir], 300);
        if (! $result->successful()) {
            File::deleteDirectory($dir);
            // Never show the token: only GitHub's own message, without the command.
            throw new RuntimeException(__('Couldn\'t clone :repo: :reason', ['repo' => "{$repo['owner']}/{$repo['name']}", 'reason' => trim(str_replace(GitHub::token(), '…', $result->errorOutput()))]));
        }

        // The working copy replaces the installed one (Composer links web/modules/<folder>).
        UpdateJob::queue('module', '', $package, "modules/{$folder}");

        return $folder;
    }
}
