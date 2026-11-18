@php
    $device = session('github.device');
    $box = 'rounded-md border px-4 py-3';
    $tokenSaved = \App\Models\Setting::getSecret('github.packagist_token') !== null;
@endphp

{{-- The settings, and the connected account, side by side. --}}
<div class="grid grid-cols-1 items-start gap-6 lg:col-span-2 lg:grid-cols-2">
    <x-settings-card :title="__('GitHub and Packagist')" :saved="(bool) session('github_status')">
        <form method="POST" action="{{ route('github.save') }}" class="space-y-4">
            @csrf
            @if (Route::has('docs.show'))
                <p><a href="{{ route('docs.show', 'github') }}" class="font-medium text-indigo-600 dark:text-indigo-400 underline">{{ __('How to set up GitHub') }}</a></p>
            @endif
            <div>
                <x-input-label for="client_id" :value="__('GitHub OAuth app Client ID')" />
                <x-text-input id="client_id" name="client_id" class="mt-1 block w-full font-mono" type="text" :value="old('client_id', \App\Models\Setting::get('github.client_id'))" required autocapitalize="off" autocomplete="off" />
                <x-input-error :messages="$errors->get('client_id')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="packagist_token" :value="__('Packagist API token')" />
                <x-text-input id="packagist_token" name="token" class="mt-1 block w-full" type="password" :placeholder="$tokenSaved ? __('Saved') : ''" :required="! $tokenSaved" autocomplete="off" />
                <x-input-error :messages="$errors->get('token')" class="mt-2" />
            </div>
            <div class="flex justify-end">
                <x-primary-button>{{ __('Check and save') }}</x-primary-button>
            </div>
        </form>
    </x-settings-card>

    @if (\TornadoEngine\GitHub\GitHub::available())
        <x-settings-card :title="__('GitHub account')">
            @if ($errors->has('github'))
                <p class="{{ $box }} border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-300">{{ $errors->first('github') }}</p>
            @endif

            @if (\TornadoEngine\GitHub\GitHub::connected())
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p>{{ __('Connected as :login.', ['login' => \TornadoEngine\GitHub\GitHub::login()]) }}</p>
                    <form method="POST" action="{{ route('github.disconnect') }}">
                        @csrf
                        <x-secondary-button type="submit">{{ __('Disconnect') }}</x-secondary-button>
                    </form>
                </div>
            @elseif ($device)
                {{-- Asks the website until GitHub says the code was entered, waiting as long as the website says. --}}
                <div class="space-y-3"
                     x-data="{
                         error: null,
                         async poll() {
                             let wait = {{ (int) $device['interval'] }};
                             try {
                                 const response = await fetch(@js(route('github.check')), { method: 'POST', redirect: 'manual', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()), 'X-Requested-With': 'XMLHttpRequest' } });
                                 if (! response.ok) { this.error = @js(__('Something went wrong. Reload the page.')); return; }
                                 const data = await response.json();
                                 if (data.error) { this.error = data.error; return; }
                                 if (data.done) { this.$refs.connected.submit(); return; }
                                 wait = data.interval ?? wait;
                             } catch (e) {}
                             setTimeout(() => this.poll(), wait * 1000);
                         },
                     }"
                     x-init="setTimeout(() => poll(), {{ (int) $device['interval'] }} * 1000)">
                    <p>{!! __('Open :link and enter this code:', ['link' => '<a href="'.e($device['uri']).'" target="_blank" rel="noopener" class="font-medium text-indigo-600 dark:text-indigo-400 underline">'.e($device['uri']).'</a>']) !!}</p>
                    <x-copy-code :text="$device['user_code']" />
                    <p x-show="! error" class="text-gray-600 dark:text-gray-400">{{ __('Waiting for GitHub…') }}</p>
                    <p x-show="error" x-cloak class="text-red-700 dark:text-red-400" x-text="error"></p>
                    <form x-ref="connected" method="POST" action="{{ route('github.connected') }}" class="hidden">@csrf</form>
                </div>
            @else
                <form method="POST" action="{{ route('github.connect') }}" class="flex justify-end">
                    @csrf
                    <x-primary-button>{{ __('Connect GitHub') }}</x-primary-button>
                </form>
            @endif
        </x-settings-card>
    @endif
</div>
