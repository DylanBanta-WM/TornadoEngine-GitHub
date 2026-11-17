<?php

namespace TornadoEngine\GitHub;

use App\Administration\Administration;
use App\Harness\HarnessMcp;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use TornadoEngine\Access\Access;

class GitHubServiceProvider extends ServiceProvider
{
    public function boot(Administration $administration, HarnessMcp $mcp): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'github');
        $this->loadRoutesFrom(__DIR__.'/../routes/github.php');

        $administration->tab('github', __('GitHub'), order: 60);
        $administration->card('github::administration-card', tab: 'github');

        // Who can publish modules: the setup admin, and roles given it.
        Gate::define('github.publish', fn (User $user) => (bool) $user->is_admin);
        $this->callAfterResolving(Access::class, function (Access $access) {
            $access->permission('github.publish', __('Publish modules to GitHub and Packagist'), 'GitHub');
        });

        // A Publish button on local modules on /modules.
        $this->callAfterResolving('TornadoEngine\\ModuleFinder\\ModuleActions', function ($actions) {
            $actions->add('github::publish');
        });

        $this->callAfterResolving('TornadoEngine\Docs\Docs', function ($docs) {
            $docs->add('github', __('Set up GitHub'), 'github::docs.setup', order: 50);
        });

        // Chat's tool for publishing (the website does it; Claude never gets the tokens).
        $mcp->server('tornado-github', '/mcp/github');
    }
}
