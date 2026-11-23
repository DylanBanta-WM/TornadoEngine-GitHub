{{-- On /modules, for modules in web/modules (made here, for example by Chat). --}}
@php
    $folder = $listing->installed !== null ? \TornadoEngine\GitHub\Publisher::folderFor($listing->package) : null;
    // Modules this site maintains (the connected account can push to their repository) can be worked on here.
    $maintains = $listing->installed !== null && $listing->url && \TornadoEngine\GitHub\GitHub::connected()
        && app(\TornadoEngine\GitHub\Maintainer::class)->canPush($listing->package);
@endphp
@if ($maintains && ! $folder)
    @can('github.publish')
        <form method="POST" action="{{ route('github.work-on') }}" class="flex w-full items-center justify-end gap-3">
            @csrf
            <input type="hidden" name="package" value="{{ $listing->package }}">
            <span class="text-sm text-gray-600 dark:text-gray-400">{{ __('You maintain this') }}</span>
            <x-secondary-button type="submit">{{ __('Work on it') }}</x-secondary-button>
        </form>
    @endcan
@endif
@if ($folder && (! $listing->url || $maintains) && \TornadoEngine\GitHub\GitHub::connected())
    @can('github.publish')
        <div x-data="{ open: false }" class="w-full">
            <div class="flex justify-end">
                <x-secondary-button type="button" x-on:click="open = ! open">{{ __('Publish') }}</x-secondary-button>
            </div>
            <form x-show="open" x-cloak method="POST" action="{{ route('github.publish') }}" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-4 sm:items-end">
                @csrf
                <input type="hidden" name="package" value="{{ $listing->package }}">
                @php($select = 'mt-1 block w-full min-h-11 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 text-base focus:border-indigo-500 focus:ring-indigo-500')
                @if (\TornadoEngine\GitHub\Publisher::official($listing->package))
                    {{-- Official modules always go to the official account, public on Packagist. --}}
                    <div class="sm:col-span-2 text-sm">
                        <p class="font-medium text-gray-700 dark:text-gray-300">{{ __('Official module') }}</p>
                        <p class="mt-1 text-gray-600 dark:text-gray-400">{{ config('tornado.official_owner') }}/{{ \TornadoEngine\GitHub\Publisher::officialRepository($folder) }} · {{ __('Public, and on Packagist') }}</p>
                        <input type="hidden" name="owner" value="{{ config('tornado.official_owner') }}">
                        <input type="hidden" name="visibility" value="packagist">
                    </div>
                @else
                    <div>
                        <x-input-label :for="'owner-'.$folder" :value="__('Owner')" />
                        <select id="owner-{{ $folder }}" name="owner" class="{{ $select }}">
                            @foreach (json_decode(\App\Models\Setting::get('github.owners') ?? 'null', true) ?: [\TornadoEngine\GitHub\GitHub::login()] as $owner)
                                <option value="{{ $owner }}">{{ $owner }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label :for="'visibility-'.$folder" :value="__('Visibility')" />
                        <select id="visibility-{{ $folder }}" name="visibility" class="{{ $select }}">
                            @foreach (\TornadoEngine\GitHub\Publisher::VISIBILITIES as $value => $label)
                                <option value="{{ $value }}" @disabled($value === 'packagist' && ! \TornadoEngine\GitHub\Publisher::packagistConfigured())>{{ __($label) }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div>
                    <x-input-label :for="'version-'.$folder" :value="__('Version')" />
                    <x-text-input :id="'version-'.$folder" name="version" class="mt-1 block w-full font-mono" type="text" :value="\TornadoEngine\GitHub\Publisher::nextVersion($folder)" required />
                </div>
                <x-primary-button class="justify-center">{{ __('Publish') }}</x-primary-button>
            </form>
        </div>
    @endcan
@endif
