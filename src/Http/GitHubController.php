<?php

namespace TornadoEngine\GitHub\Http;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use TornadoEngine\GitHub\GitHub;
use TornadoEngine\GitHub\Publisher;

class GitHubController extends Controller
{
    /** The district's GitHub OAuth app; only saved if GitHub accepts it. */
    public function app(Request $request): RedirectResponse
    {
        $clientId = trim((string) $request->validate(['client_id' => ['required', 'string', 'max:100']])['client_id']);
        if ($problem = GitHub::check($clientId)) {
            return back()->withErrors(['client_id' => $problem])->withInput();
        }
        Setting::put('github.client_id', $clientId);
        GitHub::disconnect();

        return back();
    }

    /** Starts GitHub's device flow: the page shows the code to enter at github.com. */
    public function connect(Request $request, GitHub $github): RedirectResponse
    {
        try {
            $device = $github->startConnecting();
        } catch (RuntimeException $e) {
            return back()->withErrors(['github' => $e->getMessage()]);
        }

        $request->session()->put('github.device', ['code' => $device['device_code'], 'user_code' => $device['user_code'], 'uri' => $device['verification_uri'], 'interval' => (int) ($device['interval'] ?? 5)]);

        return back();
    }

    /** Asked every few seconds by the page while it waits for the code to be entered. */
    public function check(Request $request, GitHub $github): JsonResponse
    {
        $device = $request->session()->get('github.device');
        if (! $device) {
            return response()->json(['done' => true]);
        }

        try {
            $connected = $github->finishConnecting($device['code']);
        } catch (RuntimeException $e) {
            $request->session()->forget('github.device');

            return response()->json(['done' => true, 'error' => $e->getMessage()]);
        }

        if ($connected) {
            $request->session()->forget('github.device');
            try {
                Setting::put('github.owners', json_encode($github->owners()));
            } catch (Throwable) {
            }
        }

        return response()->json(['done' => $connected]);
    }

    public function disconnect(Request $request): RedirectResponse
    {
        GitHub::disconnect();
        $request->session()->forget('github.device');

        return back();
    }

    /** The Packagist account for "Public with Packagist"; only saved if Packagist accepts it. */
    public function packagist(Request $request): RedirectResponse
    {
        $data = $request->validate(['username' => ['required', 'string', 'max:255'], 'token' => ['required', 'string', 'max:255']]);

        $response = Http::withToken("{$data['username']}:{$data['token']}")->acceptJson()->timeout(15)->post('https://packagist.org/api/create-package', ['repository' => '']);
        if (str_contains(strtolower(json_encode($response->json()) ?: ''), 'username/apitoken') || $response->status() === 403) {
            return back()->withErrors(['packagist' => __('Packagist didn\'t accept that username and API token.')])->withInput($request->only('username'));
        }

        Setting::put('github.packagist_username', trim($data['username']));
        Setting::putSecret('github.packagist_token', trim($data['token']));

        return back()->with('github_status', __('Saved.'));
    }

    public function publish(Request $request, Publisher $publisher): RedirectResponse
    {
        $data = $request->validate([
            'package' => ['required', 'string'],
            'owner' => ['required', 'string', 'max:100'],
            'visibility' => ['required', 'in:'.implode(',', array_keys(Publisher::VISIBILITIES))],
            'version' => ['required', 'string', 'max:20'],
        ]);

        $folder = Publisher::folderFor($data['package']);
        try {
            $result = $folder ? $publisher->publish($folder, $data['owner'], $data['visibility'], $data['version']) : throw new RuntimeException(__('That isn\'t a local module.'));
        } catch (Throwable $e) {
            return back()->withErrors(['modules' => $e->getMessage()]);
        }

        return back()->with('modules_status', __('Published version :version to :url.', ['version' => $result['version'], 'url' => $result['packagist'] ?? $result['url']]));
    }
}
