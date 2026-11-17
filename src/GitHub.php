<?php

namespace TornadoEngine\GitHub;

use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The connected GitHub account: signing in with GitHub's device flow (a code the person enters
 * at github.com), and the API calls publishing needs.
 */
class GitHub
{
    /** Private repos need "repo"; organizations need "read:org"; Packagist's webhook needs "admin:repo_hook". */
    public const SCOPES = 'repo read:org admin:repo_hook';

    public static function available(): bool
    {
        return self::clientId() !== '';
    }

    /** The district's own GitHub OAuth app (public; device flow needs no secret). */
    public static function clientId(): string
    {
        return (string) (config('github.client_id') ?: Setting::get('github.client_id'));
    }

    public static function connected(): bool
    {
        return Setting::getSecret('github.token') !== null;
    }

    public static function login(): ?string
    {
        return Setting::get('github.login');
    }

    /** @return array{device_code: string, user_code: string, verification_uri: string, interval: int, expires_in: int} */
    public function startConnecting(): array
    {
        $response = Http::asForm()->acceptJson()->timeout(15)->post('https://github.com/login/device/code', ['client_id' => self::clientId(), 'scope' => self::SCOPES]);
        if (! $response->successful() || ! $response->json('device_code')) {
            throw new RuntimeException(__('GitHub didn\'t start the connection. Try again.'));
        }

        return $response->json();
    }

    /**
     * Checks whether the person has entered the code yet. Returns true once connected, false while waiting.
     */
    public function finishConnecting(string $deviceCode): bool
    {
        $response = Http::asForm()->acceptJson()->timeout(15)->post('https://github.com/login/oauth/access_token', [
            'client_id' => self::clientId(),
            'device_code' => $deviceCode,
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
        ]);

        if ($token = $response->json('access_token')) {
            Setting::putSecret('github.token', $token);
            Setting::put('github.login', $this->api()->get('/user')->throw()->json('login'));

            return true;
        }

        return match ($response->json('error')) {
            'authorization_pending', 'slow_down' => false,
            'expired_token' => throw new RuntimeException(__('The code expired. Start again.')),
            'access_denied' => throw new RuntimeException(__('The connection was turned down on GitHub.')),
            default => throw new RuntimeException(__('GitHub didn\'t connect: :error', ['error' => $response->json('error_description') ?? $response->json('error') ?? $response->status()])),
        };
    }

    /** Whether GitHub knows this Client ID and has Device Flow turned on for it. */
    public static function check(string $clientId): ?string
    {
        $response = Http::asForm()->acceptJson()->timeout(15)->post('https://github.com/login/device/code', ['client_id' => $clientId, 'scope' => self::SCOPES]);

        return match (true) {
            (bool) $response->json('device_code') => null,
            $response->json('error') === 'device_flow_disabled' => __('Turn on Device Flow for this OAuth app on GitHub.'),
            default => __('GitHub doesn\'t know this Client ID.'),
        };
    }

    public static function disconnect(): void
    {
        Setting::put('github.token', null);
        Setting::put('github.login', null);
    }

    /**
     * Where repositories can go: the account, then its organizations.
     *
     * @return list<string>
     */
    public function owners(): array
    {
        $orgs = $this->api()->get('/user/orgs', ['per_page' => 100])->json('*.login') ?? [];

        return array_values(array_unique(array_merge([(string) self::login()], $orgs)));
    }

    public function api(?string $token = null): PendingRequest
    {
        return Http::baseUrl('https://api.github.com')
            ->withToken($token ?? (string) Setting::getSecret('github.token'))
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'Tornado-Engine'])
            ->timeout(30);
    }

    public static function token(): string
    {
        return (string) Setting::getSecret('github.token');
    }
}
