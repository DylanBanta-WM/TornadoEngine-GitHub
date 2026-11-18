@php
    $code = 'rounded bg-gray-100 dark:bg-gray-900 px-1 py-0.5 font-mono text-[13px] text-gray-900 dark:text-gray-100';
    $link = 'font-medium text-indigo-600 dark:text-indigo-400 underline';
@endphp

<x-setup.header :title="__('Set up GitHub')" />

<div class="mt-6 px-6 sm:px-8 pb-6 sm:pb-8 space-y-6 text-sm text-gray-700 dark:text-gray-300">
    <section class="space-y-2">
        <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('1. Make an OAuth app on GitHub') }}</h2>
        <ol class="list-decimal ps-5 space-y-1">
            <li>{!! __('Sign in to GitHub with the account modules will be published from, and open :link.', ['link' => '<a href="https://github.com/settings/applications/new" target="_blank" rel="noopener" class="'.$link.'">'.e(__('New OAuth App')).'</a>']) !!}</li>
            <li>{!! __('Application name: :name.', ['name' => '<code class="'.$code.'">Tornado Engine</code>']) !!}</li>
            <li>{!! __('Homepage URL and Authorization callback URL: GitHub needs both, but Tornado Engine doesn\'t use them. Your Tornado Engine address works for both, for example :example.', ['example' => '<code class="'.$code.'">'.e(\App\Support\Setup::address() ?? 'https://te.district.org').'</code>']) !!}</li>
            <li>{{ __('Click Register application.') }}</li>
            <li>{{ __('Tick Enable Device Flow and click Update application.') }}</li>
            <li>{{ __('Copy the Client ID. You don\'t need a client secret.') }}</li>
        </ol>
    </section>

    <section class="space-y-2">
        <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('2. Packagist') }}</h2>
        <ol class="list-decimal ps-5 space-y-1">
            <li>{!! __('Open :link, click Log in, and choose GitHub. Use the same GitHub account as above.', ['link' => '<a href="https://packagist.org/login/" target="_blank" rel="noopener" class="'.$link.'">packagist.org</a>']) !!}</li>
            <li>{!! __('Open :link, click Show API Token, and copy the Main API Token (not the Safe one).', ['link' => '<a href="https://packagist.org/profile/" target="_blank" rel="noopener" class="'.$link.'">'.e(__('your Packagist profile')).'</a>']) !!}</li>
        </ol>
    </section>

    <section class="space-y-2">
        <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('3. Connect it') }}</h2>
        <ol class="list-decimal ps-5 space-y-1">
            <li>{{ __('In Administration → GitHub, enter the Client ID and the Packagist API token, and click Check and save.') }}</li>
            <li>{{ __('Click Connect GitHub, open the link it shows, enter the code, and approve.') }}</li>
        </ol>
    </section>
</div>
