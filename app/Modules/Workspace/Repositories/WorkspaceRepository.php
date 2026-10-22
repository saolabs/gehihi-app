<?php

namespace App\Modules\Workspace\Repositories;

use App\Modules\Workspace\Models\Conversation;
use App\Modules\Workspace\Models\Message;
use App\Modules\Workspace\Models\Project;
use App\Modules\Workspace\Models\SourceFile;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Facades\DB;

class WorkspaceRepository implements WorkspaceRepositoryInterface
{
    public function projects(string $ws): array
    {
        return Project::where('workspace_id', $ws)->latest('updated_at')->get()->toArray();
    }

    public function project(string $ws, string $id): Project
    {
        return Project::where('workspace_id', $ws)->findOrFail($id);
    }

    public function saveProject(string $ws, ?string $id, array $data): Project
    {
        return DB::transaction(function () use ($ws, $id, $data) {
            $p = $id ? Project::where('workspace_id', $ws)->lockForUpdate()->findOrFail($id) : new Project(['workspace_id' => $ws]);
            $changed = ! $p->exists || ($data['instructions'] ?? $p->instructions) !== $p->instructions;
            if ($changed && $p->exists) {
                $p->revision++;
            }
            $p->fill($data);
            $p->save();
            $p->refresh();
            if ($changed) {
                DB::table('instruction_revisions')->insert(['project_id' => $p->id, 'revision' => $p->revision, 'content' => $p->instructions, 'created_at' => now()]);
            }

            return $p;
        });
    }

    public function deleteProject(string $ws, string $id): void
    {
        $p = $this->project($ws, $id);
        DB::transaction(function () use ($p) {
            Conversation::where('project_id', $p->id)->update(['project_id' => null]);
            SourceFile::where('project_id', $p->id)->delete();
            $p->delete();
        });
    }

    public function conversations(string $ws, string $search): array
    {
        return Conversation::where('workspace_id', $ws)->where('temporary', false)->when($search !== '', function ($q) use ($search) {
            $q->where(function ($q) use ($search) {
                $q->where('title', 'like', '%'.$search.'%')->orWhereIn('id', Message::select('conversation_id')->where('content', 'like', '%'.$search.'%'));
            });
        })->orderByDesc('pinned')->latest('updated_at')->limit(200)->get()->toArray();
    }

    public function conversation(string $ws, string $id): Conversation
    {
        return Conversation::where('workspace_id', $ws)->findOrFail($id);
    }

    public function createConversation(string $ws, array $data): Conversation
    {
        return Conversation::create(['workspace_id' => $ws, ...$data]);
    }

    public function updateConversation(string $ws, string $id, array $data): Conversation
    {
        $c = $this->conversation($ws, $id);
        $c->update($data);

        return $c->refresh();
    }

    public function deleteConversation(string $ws, string $id): void
    {
        $this->conversation($ws, $id)->delete();
    }

    public function messages(string $conversation): array
    {
        return Message::where('conversation_id', $conversation)->orderBy('created_at')->orderBy('id')->get()->toArray();
    }

    public function message(string $conversation, string $id): Message
    {
        return Message::where('conversation_id', $conversation)->findOrFail($id);
    }

    public function addMessage(string $conversation, array $data): Message
    {
        return Message::create(['conversation_id' => $conversation, ...$data]);
    }

    public function updateMessage(string $id, array $data): void
    {
        Message::findOrFail($id)->update($data);
    }

    public function preferences(string $ws, array $data): void
    {
        Workspace::findOrFail($ws)->update(['preferences' => $data]);
    }

    public function files(string $ws): array
    {
        return SourceFile::where('workspace_id', $ws)->latest()->get(['id', 'name', 'project_id', 'mime', 'size', 'status', 'error', 'created_at'])->toArray();
    }

    public function file(string $ws, string $id): SourceFile
    {
        return SourceFile::where('workspace_id', $ws)->findOrFail($id);
    }

    public function createFile(string $ws, array $data): SourceFile
    {
        return SourceFile::create(['workspace_id' => $ws, ...$data]);
    }

    public function updateFile(string $id, array $data): void
    {
        SourceFile::findOrFail($id)->update($data);
    }

    public function deleteFile(string $ws, string $id): void
    {
        $this->file($ws, $id)->delete();
    }

    public function purgeExpired(): array
    {
        $ids = Workspace::whereNull('user_id')->where('expires_at', '<', now())->pluck('id');
        $paths = SourceFile::withTrashed()->whereIn('workspace_id', $ids)->pluck('path')->all();
        Workspace::whereIn('id', $ids)->delete();
        $temporary = Conversation::where('temporary', true)->where('updated_at','<',now()->subDay())->get();
        foreach ($temporary as $c) {
            $c->forceDelete();
        }

        return $paths;
    }
}
