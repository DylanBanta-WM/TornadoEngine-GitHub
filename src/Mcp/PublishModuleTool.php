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
use TornadoEngine\GitHub\GitHub;
use TornadoEngine\GitHub\Publisher;

#[Name('publish_module')]
#[Description('Publishes a module from /workspace/web/modules/<folder> to GitHub: visibility private, public, or packagist (public and on Packagist). Returns where it went.')]
class PublishModuleTool extends Tool
{
    public function handle(Request $request, Publisher $publisher): Response
    {
        // Only for people allowed to publish: the person in the chat that's running.
        $task = HarnessTask::where('source', 'chat')->where('status', 'running')->latest('id')->first();
        $user = $task && class_exists(Conversation::class)
            ? User::find(Conversation::find((int) $task->reference)?->user_id) : null;
        if (! $user || ! $user->can('github.publish')) {
            return Response::error('The person you\'re chatting with isn\'t allowed to publish modules.');
        }

        $folder = (string) $request->get('folder', '');
        try {
            $result = $publisher->publish($folder, (string) ($request->get('owner') ?: GitHub::login()), (string) $request->get('visibility', ''), (string) ($request->get('version') ?: Publisher::nextVersion($folder)), (string) $request->get('message', ''));
        } catch (Throwable $e) {
            return Response::error($e->getMessage());
        }

        return Response::text("Published version {$result['version']} to {$result['url']}".($result['packagist'] ? " and {$result['packagist']}" : '').'.');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'folder' => $schema->string()->description('The module\'s folder in web/modules.')->required(),
            'visibility' => $schema->string()->enum(['private', 'public', 'packagist'])->required(),
            'owner' => $schema->string()->description('A GitHub account or organization; the connected account if left out.'),
            'version' => $schema->string()->description('For example 1.0.0; one after the newest tag if left out.'),
            'message' => $schema->string()->description('One line saying what changed, for the commit.'),
        ];
    }
}
