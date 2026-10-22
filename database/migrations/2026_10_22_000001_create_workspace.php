<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $t->string('guest_hash')->nullable()->unique();
            $t->json('preferences')->nullable();
            $t->timestamp('expires_at')->nullable()->index();
            $t->timestamps();
        });
        Schema::create('projects', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->text('instructions')->default('');
            $t->unsignedInteger('revision')->default(1);
            $t->json('selection')->nullable();
            $t->boolean('archived')->default(false);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('instruction_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('revision');
            $t->text('content');
            $t->timestamp('created_at');
            $t->unique(['project_id', 'revision']);
        });
        Schema::create('conversations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('project_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title')->default('Cuộc trò chuyện mới');
            $t->uuid('selected_leaf_id')->nullable();
            $t->json('selection')->nullable();
            $t->boolean('pinned')->default(false);
            $t->boolean('archived')->default(false);
            $t->boolean('temporary')->default(false);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $t->uuid('parent_id')->nullable()->index();
            $t->string('role');
            $t->longText('content')->default('');
            $t->string('status')->default('completed');
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
        Schema::create('providers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('kind')->default('llm');
            $t->string('type');
            $t->string('base_url');
            $t->text('secrets')->nullable();
            $t->json('models');
            $t->json('capabilities')->nullable();
            $t->string('default_model')->default('');
            $t->boolean('enabled')->default(true);
            $t->boolean('is_default')->default(false);
            $t->timestamp('tested_at')->nullable();
            $t->string('test_status')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('provider_key_health', function (Blueprint $t) {
            $t->string('fingerprint')->primary();
            $t->unsignedInteger('cursor')->default(0);
            $t->timestamp('cooldown_until')->nullable();
            $t->timestamps();
        });
        Schema::create('files', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('project_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name');
            $t->string('path');
            $t->string('mime');
            $t->unsignedInteger('size');
            $t->string('status');
            $t->longText('content')->nullable();
            $t->string('error')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('generation_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('message_id')->constrained('messages')->cascadeOnDelete();
            $t->string('request_id');
            $t->string('mode');
            $t->string('status')->default('queued');
            $t->string('progress')->default('Đang chờ xử lý');
            $t->json('snapshot');
            $t->json('checkpoint')->nullable();
            $t->json('sources')->nullable();
            $t->string('error')->nullable();
            $t->string('stop_reason')->nullable();
            $t->unsignedInteger('reserved_tokens');
            $t->unsignedInteger('used_tokens')->default(0);
            $t->json('actual_model')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'request_id']);
        });
        Schema::create('run_operations', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('run_id')->constrained('generation_runs')->cascadeOnDelete();
            $t->string('operation_key');
            $t->string('kind');
            $t->json('result')->nullable();
            $t->unsignedInteger('tokens')->default(0);
            $t->string('metering')->default('estimated');
            $t->timestamps();
            $t->unique(['run_id', 'operation_key']);
        });
    }

    public function down(): void
    {
        foreach (['run_operations', 'generation_runs', 'files', 'provider_key_health', 'providers', 'messages', 'conversations', 'instruction_revisions', 'projects', 'workspaces'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
