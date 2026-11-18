<?php

namespace TornadoEngine\GitHub\Http;

use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use RuntimeException;
use Throwable;
use TornadoEngine\GitHub\GitHub;
use TornadoEngine\GitHub\Publisher;

class GitHubController extends Controller
{
    /** The district's GitHub OAuth app and Packagist API token; only saved if GitHub and Packagist accept them. */
    public function save(Request $request): RedirectResponse
    {
        $tokenSaved = Setting::getSecret('github.packagist_token') !== null;
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:100'],
            'token' => [$tokenSaved ? 'nullable' : 'required', 'string', 'max:255'],
        ]);
        $clientId = trim($data['client_id']);
        $token = trim((string) ($data['token'] ?? ''));

        $errors = [];
        if ($problem = GitHub::check($clientId)) {
            $errors['client_id'] = $problem;
        }
        // The Packagist username is the GitHub login; without a connected account, it's checked once one connects.
        if ($token !== '' && ($login = GitHub::login()) !== null) {
            try {
                if (! Publisher::packagistAccepts($login, $token)) {
                    $errors['token'] = __('Packagist didn\'t accept this API token for :login.', ['login' => $login]);
                }
            } catch (ConnectionException) {
                $errors['token'] = __('Packagist didn\'t answer. Try again.');
            }
        }
        if ($errors) {
            return back()->withErrors($errors)->withInput($request->only('client_id'));
        }

        if ($clientId !== Setting::get('github.client_id')) {
            Setting::put('github.client_id', $clientId);
            GitHub::disconnect();
            $request->session()->forget('github.device');
        }
        if ($token !== '') {
            Setting::putSecret('github.packagist_token', $token);
        }

        return back()->with('github_status', __('Saved.'));
    }

    /** Starts GitHub's device flow: the page shows the code to enter at github.com. */
    public function connect(Request $request, GitHub $github): RedirectResponse
    {
        try {
            $device = $github->startConnecting();
        } catch (RuntimeException $e) {
            return back()->withErrors(['github' => $e->getMessage()]);
        }

        $interval = max(5, (int) ($device['interval'] ?? 5));
        $request->session()->put('github.device', ['code' => $device['device_code'], 'user_code' => $device['user_code'], 'uri' => $device['verification_uri'], 'interval' => $interval, 'next' => self::now() + $interval]);

        return back();
    }

    /**
     * Asked by the page while it waits for the code to be entered. Answers {done, interval} (seconds until it may ask
     * again) or {done, error}. GitHub is asked at most once per interval, however often the page asks.
     */
    public function check(Request $request, GitHub $github): JsonResponse
    {
        $device = $request->session()->get('github.device');
        if (! $device) {
            return response()->json(['done' => true]);
        }

        $wait = (int) ceil(($device['next'] ?? 0) - self::now());
        if ($wait > 0) {
            return response()->json(['done' => false, 'interval' => $wait]);
        }

        try {
            $interval = $github->finishConnecting($device['code'], (int) $device['interval']);
        } catch (ConnectionException) {
            $interval = (int) $device['interval'];
        } catch (Throwable $e) {
            $request->session()->forget('github.device');

            return response()->json(['done' => true, 'error' => $e instanceof RuntimeException ? $e->getMessage() : __('GitHub didn\'t connect. Start again.')]);
        }

        if ($interval !== null) {
            // A second to spare, so GitHub never sees two requests closer than the interval.
            $request->session()->put('github.device', [...$device, 'interval' => $interval, 'next' => self::now() + $interval + 1]);

            return response()->json(['done' => false, 'interval' => $interval + 1]);
        }

        $request->session()->forget('github.device');
        try {
            Setting::put('github.owners', json_encode($github->owners()));
        } catch (Throwable) {
        }
        $this->checkPackagistToken($request);

        return response()->json(['done' => true]);
    }

    /** The page posts here once connected, so setting up moves on (App\Http\Middleware\AdvanceSetup). */
    public function connected(Request $request): RedirectResponse
    {
        $request->session()->reflash();

        return back();
    }

    public function disconnect(Request $request): RedirectResponse
    {
        GitHub::disconnect();
        $request->session()->forget('github.device');

        return back();
    }

    /** Seconds, to the millisecond (and following the clock in tests). */
    private static function now(): float
    {
        return now()->getTimestampMs() / 1000;
    }

    /** A Packagist token saved before an account was connected is checked now, with the account's login. */
    private function checkPackagistToken(Request $request): void
    {
        $token = Setting::getSecret('github.packagist_token');
        $login = (string) GitHub::login();
        try {
            if ($token === null || Publisher::packagistAccepts($login, $token)) {
                return;
            }
        } catch (ConnectionException) {
            return;
        }
        Setting::put('github.packagist_token', null);
        $request->session()->flash('errors', (new ViewErrorBag)->put('default', new MessageBag(['token' => __('Packagist didn\'t accept this API token for :login.', ['login' => $login])])));
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
