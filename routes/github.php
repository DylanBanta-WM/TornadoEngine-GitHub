<?php

use App\Http\Middleware\AuthenticateHarness;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use TornadoEngine\Broker\Http\OnlyFromHarness;
use TornadoEngine\GitHub\Http\GitHubController;
use TornadoEngine\GitHub\Mcp\GitHubServer;

// Chat's publish_module tool, for the harness only.
Mcp::web('/mcp/github', GitHubServer::class)->middleware([OnlyFromHarness::class, AuthenticateHarness::class]);

Route::middleware(['web', 'auth', 'can:administration'])->prefix('administration/github')->name('github.')->group(function () {
    Route::post('/app', [GitHubController::class, 'app'])->name('app');
    Route::post('/connect', [GitHubController::class, 'connect'])->name('connect');
    Route::post('/connect/check', [GitHubController::class, 'check'])->name('check');
    Route::post('/disconnect', [GitHubController::class, 'disconnect'])->name('disconnect');
    Route::post('/packagist', [GitHubController::class, 'packagist'])->name('packagist');
});

Route::middleware(['web', 'auth', 'can:github.publish'])->post('/modules/publish', [GitHubController::class, 'publish'])->name('github.publish');
