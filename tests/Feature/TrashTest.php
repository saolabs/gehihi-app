<?php

namespace Tests\Feature;

use App\Modules\Workspace\Models\Conversation;
use App\Modules\Workspace\Models\Project;
use App\Modules\Workspace\Models\SourceFile;
use App\Modules\Workspace\Services\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TrashTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
    }

    private function file(?string $project = null): array
    {
        return $this->postJson('/api/files', ['file' => UploadedFile::fake()->createWithContent('n.md', '# N'), 'project_id' => $project])->assertCreated()->json('data');
    }

    public function test_deleted_items_are_listed_and_restored_only_within_their_workspace(): void
    {
        $c = $this->postJson('/api/conversations', [])->json('data.id');
        $this->deleteJson('/api/conversations/'.$c)->assertOk();
        $this->getJson('/api/trash')->assertOk()->assertJsonPath('data.conversations.0.id', $c);

        $this->withSession(['guest_token' => 'other-guest']);
        $this->getJson('/api/trash')->assertJsonCount(0, 'data.conversations');
        $this->postJson('/api/trash/conversations/'.$c.'/restore')->assertNotFound();
        $this->postJson('/api/trash/users/'.$c.'/restore')->assertNotFound();
    }

    public function test_restoring_a_project_brings_back_files_deleted_with_it(): void
    {
        $p = $this->postJson('/api/projects', ['name' => 'Dự án'])->json('data.id');
        $c = $this->postJson('/api/conversations', ['project_id' => $p])->json('data.id');
        $f = $this->file($p)['id'];
        $this->deleteJson('/api/projects/'.$p)->assertOk();
        $this->assertSoftDeleted('files', ['id' => $f]);

        $this->postJson('/api/trash/projects/'.$p.'/restore')->assertOk()->assertJsonCount(0, 'data.projects');
        $this->assertNotSoftDeleted('projects', ['id' => $p]);
        $this->assertNotSoftDeleted('files', ['id' => $f]);
        $this->assertNull(Conversation::find($c)->project_id); // hội thoại đã được tách khỏi dự án lúc xoá
    }

    public function test_restored_file_of_a_still_deleted_project_moves_out_of_it(): void
    {
        $p = $this->postJson('/api/projects', ['name' => 'Dự án'])->json('data.id');
        $f = $this->file($p)['id'];
        $this->deleteJson('/api/projects/'.$p)->assertOk();
        $this->postJson('/api/trash/files/'.$f.'/restore')->assertOk();
        $this->assertNull(SourceFile::find($f)->project_id);
    }

    public function test_forget_removes_rows_and_stored_files_and_purge_empties_old_trash(): void
    {
        $f = $this->file();
        $path = SourceFile::find($f['id'])->path;
        Storage::disk('local')->assertExists($path);
        $this->deleteJson('/api/files/'.$f['id'])->assertOk();
        $this->deleteJson('/api/trash/files/'.$f['id'])->assertOk()->assertJsonCount(0, 'data.files');
        $this->assertNull(SourceFile::withTrashed()->find($f['id']));
        Storage::disk('local')->assertMissing($path);

        $p = $this->postJson('/api/projects', ['name' => 'Cũ'])->json('data.id');
        $this->deleteJson('/api/projects/'.$p)->assertOk();
        Project::withTrashed()->find($p)->forceFill(['deleted_at' => now()->subDays(31)])->save();
        app(FileService::class)->purge();
        $this->assertNull(Project::withTrashed()->find($p));
    }
}
