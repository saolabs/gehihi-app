<?php

namespace App\Modules\Workspace\Services;

use App\Modules\Workspace\Jobs\ExtractFile;
use App\Modules\Workspace\Repositories\WorkspaceRepositoryInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class FileService
{
    public function __construct(private WorkspaceRepositoryInterface $repo) {}

    public function upload(string $ws, UploadedFile $upload, ?string $project): array
    {
        if ($project) {
            $this->repo->project($ws, $project);
        }
        $ext = strtolower($upload->getClientOriginalExtension());
        if (! in_array($ext, ['txt', 'md', 'csv', 'tsv', 'json', 'php', 'js', 'ts', 'py', 'html', 'css', 'pdf', 'png', 'jpg', 'jpeg', 'webp'])) {
            throw ValidationException::withMessages(['file' => 'Định dạng tệp chưa được hỗ trợ.']);
        }
        $path = $upload->store('sources', 'local');
        $file = $this->repo->createFile($ws, ['project_id' => $project, 'name' => $this->name($upload->getClientOriginalName(), $ext), 'path' => $path, 'mime' => $upload->getMimeType() ?? 'text/plain', 'size' => $upload->getSize(), 'status' => 'processing']);
        ExtractFile::dispatch($file->id)->afterCommit();

        return collect($file->refresh()->toArray())->except(['path', 'content'])->all();
    }

    /** Cắt tên dài nhưng giữ đuôi: ExtractFile nhận loại tệp theo đuôi trong tên. */
    private function name(string $original, string $ext): string
    {
        $name = basename($original);

        return mb_strlen($name) <= 180 ? $name : mb_substr($name, 0, 179 - mb_strlen($ext)).'.'.$ext;
    }

    /** Chạy trong ExtractFile: đọc văn bản/ảnh, lưu tối đa 120.000 ký tự; tệp đã bị xoá thì bỏ qua. */
    public function extract(string $id): void
    {
        $file = $this->repo->findFile($id);
        if (! $file || $file->status !== 'processing') {
            return;
        }
        $ext = strtolower(pathinfo($file->name, PATHINFO_EXTENSION));
        try {
            $absolute = Storage::disk('local')->path($file->path);
            $text = '';
            if ($ext === 'pdf') {
                $process = new Process([config('gehihi.pdftotext'), '-layout', $absolute, '-']);
                $process->setTimeout(45);
                $process->mustRun();
                $text = $process->getOutput();
                if (mb_strlen(trim($text)) < 20) {
                    throw new \RuntimeException('PDF chưa có lớp văn bản. Cần OCR hoặc xuất bản PDF có thể chọn chữ.');
                }
            } elseif (in_array($ext, ['png', 'jpg', 'jpeg', 'webp'])) {
                if (! @getimagesize($absolute)) {
                    throw new \RuntimeException('Ảnh không hợp lệ.');
                }
            } else {
                $text = Storage::disk('local')->get($file->path);
                if (! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
                    throw new \RuntimeException('Tệp cần là văn bản UTF-8.');
                }
            }
            $partial = mb_strlen($text) > 120000;
            $this->repo->updateFile($file->id, ['content' => mb_substr($text, 0, 120000), 'status' => $partial ? 'partial' : 'ready', 'error' => $partial ? 'Chỉ trích xuất 120.000 ký tự đầu.' : null]);
        } catch (\Throwable $e) {
            $this->markFailed($file->id, $ext === 'pdf' && ! str_contains($e->getMessage(), 'OCR') ? 'Chưa đọc được PDF. Máy chủ cần pdftotext (Poppler).' : $e->getMessage());
        }
    }

    public function markFailed(string $id, string $message): void
    {
        if ($this->repo->findFile($id)?->status === 'processing') {
            $this->repo->updateFile($id, ['status' => 'error', 'error' => mb_substr($message, 0, 220)]);
        }
    }

    public function download(string $ws, string $id): array
    {
        $f = $this->repo->file($ws, $id);

        return ['path' => Storage::disk('local')->path($f->path), 'name' => $f->name];
    }

    public function delete(string $ws, string $id): void
    {
        $this->repo->deleteFile($ws, $id);
    }

    public function forget(string $ws, string $type, string $id): void
    {
        foreach ($this->repo->forget($ws, $type, $id) as $path) {
            Storage::disk('local')->delete($path);
        }
    }

    public function purge(): void
    {
        foreach ($this->repo->purgeExpired() as $path) {
            Storage::disk('local')->delete($path);
        }
    }
}
