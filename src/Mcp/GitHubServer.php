<?php

namespace TornadoEngine\GitHub\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Tornado Engine GitHub')]
#[Version('0.1.0')]
#[Instructions('publish_module publishes a module from /workspace/web/modules to GitHub. For a new module, ask the person first which they want: private (GitHub only), public, or packagist (public and on Packagist, so other districts can install it), and the version. A module this site maintains (its working copy came from work_on_module) goes back to its own repository with the visibility it already has, so don\'t ask about visibility, and leave the version out to get the next one after its newest tag; ask only if the person wants a different visibility or version. Give a one-line message saying what changed. To change an installed module, call work_on_module first: it works when the connected GitHub account can push to that module\'s repository, and says so when it can\'t.')]
class GitHubServer extends Server
{
    protected array $tools = [PublishModuleTool::class, WorkOnModuleTool::class];
}
