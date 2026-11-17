<?php

namespace TornadoEngine\GitHub\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Tornado Engine GitHub')]
#[Version('0.1.0')]
#[Instructions('publish_module publishes a module from /workspace/web/modules to the district\'s GitHub. Ask the person first which they want: private (GitHub only), public, or packagist (public and on Packagist, so other districts can install it), and the version.')]
class GitHubServer extends Server
{
    protected array $tools = [PublishModuleTool::class];
}
