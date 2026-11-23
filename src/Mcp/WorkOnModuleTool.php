<?php

namespace TornadoEngine\GitHub\Mcp;

use App\Harness\HarnessTask;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;
use TornadoEngine\Chat\Models\Conversation;
use TornadoEngine\GitHub\Maintainer;

#[Name('work_on_module')]
#[Description('For an installed module this site maintains (the connected GitHub account can push to its repository): clones it into /workspace/web/modules/<folder> and links it in place of the installed copy, so you can change it there and publish a new version with publish_module. Says so if this site doesn\'t maintain it.')]
class WorkOnModuleTool extends Tool
{
    public function handle(Request $request, Maintainer $maintainer): Response
    {
        // Only for people allowed to publish: the person in the chat that's running.
        $task = HarnessTask::where('source', 'chat')->where('status', 'running')->latest('id')->first();
        $user = $task && class_exists(Conversation::class) ? User::find(Conversation::find((int) $task->reference)?->user_id) : null;
        if (! $user || ! $user->can('github.publish')) {
            return Response::error('The person you\'re chatting with isn\'t allowed to publish modules, so they can\'t work on installed ones either.');
        }

        $package = strtolower(trim((string) $request->get('package', '')));
        try {
            $folder = $maintainer->workOn($package);
        } catch (Throwable $e) {
            return Response::error($e->getMessage());
        }

        return Response::text("{$package} is in /workspace/web/modules/{$folder}, a working copy of its repository. The website links it in place of the installed copy within a minute or two. Change it there, then publish a new version with publish_module (folder {$folder}).");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'package' => $schema->string()->description('The module\'s Composer package, for example tornado-engine/chat.')->required(),
        ];
    }
}
