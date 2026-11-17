@php
    $device = session('github.device');
    $box = 'rounded-md border px-4 py-3';
@endphp

<x-settings-card :title="__('GitHub')">
    @if ($errors->has('github'))
        <p class="{{ $box }} border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-300">{{ $errors->first('github') }}</p>
    @endif

    @if (! \TornadoEngine\GitHub\GitHub::available() || $errors->has('client_id'))
        <form method="POST" action="{{ route('github.app') }}" class="space-y-3">
            @csrf
            @if (Route::has('docs.show'))
                <p><a href="{{ route('docs.show', 'github') }}" class="font-medium text-indigo-600 dark:text-indigo-400 underline">{{ __('How to set up GitHub') }}</a></p>
            @endif
            <div>
                <x-input-label for="client_id" :value="__('OAuth app Client ID')" />
                <x-text-input id="client_id" name="client_id" class="mt-1 block w-full font-mono" type="text" :value="old('client_id')" required autocapitalize="off" autocomplete="off" />
                <x-input-error :messages="$errors->get('client_id')" class="mt-2" />
            </div>
            <div class="flex justify-end">
                <x-primary-button>{{ __('Check and save') }}</x-primary-button>
            </div>
        </form>
    @elseif (\TornadoEngine\GitHub\GitHub::connected())
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p>{{ __('Connected as :login.', ['login' => \TornadoEngine\GitHub\GitHub::login()]) }}</p>
            <form method="POST" action="{{ route('github.disconnect') }}">
                @csrf
                <x-secondary-button type="submit">{{ __('Disconnect') }}</x-secondary-button>
            </form>
        </div>
    @elseif ($device)
        <div class="space-y-3" x-data="{ error: null }"
             x-init="const timer = setInterval(async () => {
                 const response = await fetch(@js(route('github.check')), { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()) } });
                 const data = await response.json();
                 if (data.error) { error = data.error; clearInterval(timer); }
                 else if (data.done) location.reload();
             }, {{ max(5, $device['interval']) * 1000 }})">
            <p>{!! __('Open :link and enter this code:', ['link' => '<a href="'.e($device['uri']).'" target="_blank" rel="noopener" class="font-medium text-indigo-600 dark:text-indigo-400 underline">'.e($device['uri']).'</a>']) !!}</p>
            <x-copy-code :text="$device['user_code']" />
            <p x-show="! error" class="text-gray-600 dark:text-gray-400">{{ __('Waiting for GitHub…') }}</p>
            <p x-show="error" x-cloak class="text-red-700 dark:text-red-400" x-text="error"></p>
        </div>
    @else
        <form method="POST" action="{{ route('github.connect') }}" class="flex justify-end">
            @csrf
            <x-primary-button>{{ __('Connect GitHub') }}</x-primary-button>
        </form>
    @endif
</x-settings-card>

<x-settings-card :title="__('Packagist')">
    @if (session('github_status'))
        <p class="text-green-700 dark:text-green-400">{{ session('github_status') }}</p>
    @endif
    <div x-data="{ editing: @js(! \TornadoEngine\GitHub\Publisher::packagistConfigured() || $errors->has('packagist')) }" class="space-y-4">
        @if (\TornadoEngine\GitHub\Publisher::packagistConfigured())
            <div x-show="! editing" class="flex flex-wrap items-center justify-between gap-3">
                <p>{{ __('Username: :username. API token: saved.', ['username' => \App\Models\Setting::get('github.packagist_username')]) }}</p>
                <x-secondary-button type="button" x-on:click="editing = true">{{ __('Change') }}</x-secondary-button>
            </div>
        @endif
        <form x-show="editing" @if (\TornadoEngine\GitHub\Publisher::packagistConfigured()) x-cloak @endif method="POST" action="{{ route('github.packagist') }}" class="space-y-4">
            @csrf
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="packagist_username" :value="__('Username')" />
                    <x-text-input id="packagist_username" name="username" class="mt-1 block w-full" type="text" :value="old('username', \App\Models\Setting::get('github.packagist_username'))" required autocapitalize="off" />
                </div>
                <div>
                    <x-input-label for="packagist_token" :value="__('API token')" />
                    <x-text-input id="packagist_token" name="token" class="mt-1 block w-full" type="password" required autocomplete="off" />
                </div>
            </div>
            <x-input-error :messages="$errors->get('packagist')" />
            <div class="flex justify-end">
                <x-primary-button>{{ __('Check and save') }}</x-primary-button>
            </div>
        </form>
    </div>
</x-settings-card>
