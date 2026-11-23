<?php

namespace TornadoEngine\GitHub\Mcp;

use App\Harness\HarnessTask;
use App\Models\User;
use App\Patches\Patches;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;
use TornadoEngine\Chat\Models\Conversation;

#[Name('patch_file')]
#[Description('Changes a file of Core (/workspace/web/app, config, database, resources, routes, tests) or of an installed Tornado Engine module (/workspace/web/vendor/<vendor>/<module>/...) through the district\'s patch for it. Give the file\'s whole new content. The change is made now and kept as a patch in web/local/patches that goes back on after every update; if an update changes the same lines, the update still happens, the patch turns off and the bell says so. Use it only when an override (web/local), the module\'s extension points or a module of your own won\'t do, and for a module this site maintains use work_on_module instead.')]
class PatchFileTool extends Tool
{
    public function handle(Request $request): Response
    {
        // Only for admins: the person in the chat that's running.
        $task = HarnessTask::where('source', 'chat')->where('status', 'running')->latest('id')->first();
        $user = $task && class_exists(Conversation::class) ? User::find(Conversation::find((int) $task->reference)?->user_id) : null;
        if (! $user || ! $user->can('administration')) {
            return Response::error('Only admins can change Core or installed modules.');
        }
        if (! class_exists(Patches::class)) {
            return Response::error('This Core version can\'t keep patches yet. Update Core first (Administration → Updates).');
        }

        try {
            $target = app(Patches::class)->change((string) $request->get('path', ''), (string) $request->get('content', ''));
        } catch (Throwable $e) {
            return Response::error($e->getMessage());
        }

        return Response::text("Changed it, and kept the change in the patch for {$target} (web/local/patches). It goes back on after every update; tell the person it may need redoing if an update changes the same lines.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('The file, for example /workspace/web/vendor/tornado-engine/chat/src/Chat.php or /workspace/web/app/Providers/AppServiceProvider.php.')->required(),
            'content' => $schema->string()->description('The file\'s whole new content.')->required(),
        ];
    }
}
