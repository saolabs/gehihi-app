<?php

namespace App\Modules\Workspace\Repositories;

use App\Modules\Workspace\Models\Conversation;
use App\Modules\Workspace\Models\Message;
use App\Modules\Workspace\Models\Project;
use App\Modules\Workspace\Models\SourceFile;

interface WorkspaceRepositoryInterface
{
    public function projects(string $ws): array;

    public function project(string $ws, string $id): Project;

    public function saveProject(string $ws, ?string $id, array $data): Project;

    public function deleteProject(string $ws, string $id): void;

    public function conversations(string $ws, string $search): array;

    public function conversation(string $ws, string $id): Conversation;

    public function createConversation(string $ws, array $data): Conversation;

    public function updateConversation(string $ws, string $id, array $data): Conversation;

    public function deleteConversation(string $ws, string $id): void;

    public function messages(string $conversation): array;

    public function message(string $conversation, string $id): Message;

    public function addMessage(string $conversation, array $data): Message;

    public function updateMessage(string $id, array $data): void;

    public function preferences(string $ws, array $data): void;

    public function files(string $ws): array;

    public function file(string $ws, string $id): SourceFile;

    public function createFile(string $ws, array $data): SourceFile;

    public function updateFile(string $id, array $data): void;

    public function deleteFile(string $ws, string $id): void;

    public function purgeExpired(): array;
}
