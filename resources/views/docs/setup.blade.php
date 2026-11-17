@php($code = 'rounded bg-gray-100 dark:bg-gray-900 px-1 py-0.5 font-mono text-[13px] text-gray-900 dark:text-gray-100')

<x-setup.header :title="__('Set up GitHub')" />

<div class="mt-6 px-6 sm:px-8 pb-6 sm:pb-8 space-y-6 text-sm text-gray-700 dark:text-gray-300">
    <section class="space-y-2">
        <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('1. Make an OAuth app on GitHub') }}</h2>
        <ol class="list-decimal ps-5 space-y-1">
            <li>{!! __('In GitHub, open :path.', ['path' => '<a href="https://github.com/settings/applications/new" target="_blank" rel="noopener" class="font-medium text-indigo-600 dark:text-indigo-400 underline">Settings → Developer settings → OAuth Apps → New OAuth App</a>']) !!}</li>
            <li>{{ __('Application name: Tornado Engine. Homepage URL and Authorization callback URL: your Tornado Engine address.') }}</li>
            <li>{{ __('Click Register application, tick Enable Device Flow, and save.') }}</li>
            <li>{{ __('Copy the Client ID. No client secret is needed.') }}</li>
        </ol>
    </section>

    <section class="space-y-2">
        <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('2. Connect') }}</h2>
        <ol class="list-decimal ps-5 space-y-1">
            <li>{{ __('In Administration → GitHub, enter the Client ID.') }}</li>
            <li>{{ __('Click Connect GitHub and enter the code it shows at github.com.') }}</li>
        </ol>
    </section>

    <section class="space-y-2">
        <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('3. Packagist (to share modules)') }}</h2>
        <p>{!! __('Make an account on :packagist and copy your API token from your profile. Enter your username and token in Administration → GitHub.', ['packagist' => '<a href="https://packagist.org" target="_blank" rel="noopener" class="font-medium text-indigo-600 dark:text-indigo-400 underline">Packagist</a>']) !!}</p>
    </section>
</div>
