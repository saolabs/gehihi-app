<?php

namespace App\Modules\Workspace\Services;

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
        $file = $this->repo->createFile($ws, ['project_id' => $project, 'name' => mb_substr(basename($upload->getClientOriginalName()), 0, 180), 'path' => $path, 'mime' => $upload->getMimeType() ?? 'text/plain', 'size' => $upload->getSize(), 'status' => 'processing']);
        try {
            $absolute = Storage::disk('local')->path($path);
            $text = '';
            if ($ext === 'pdf') {
                $process = new Process([config('gehihi.pdftotext'), '-layout', $absolute, '-']);
                $process->setTimeout(20);
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
                $text = Storage::disk('local')->get($path);
                if (! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
                    throw new \RuntimeException('Tệp cần là văn bản UTF-8.');
                }
            }
            $partial = mb_strlen($text) > 120000;
            $this->repo->updateFile($file->id, ['content' => mb_substr($text, 0, 120000), 'status' => $partial ? 'partial' : 'ready', 'error' => $partial ? 'Chỉ trích xuất 120.000 ký tự đầu.' : null]);
        } catch (\Throwable $e) {
            $message = $ext === 'pdf' && ! str_contains($e->getMessage(), 'OCR') ? 'Chưa đọc được PDF. Máy chủ cần pdftotext (Poppler).' : $e->getMessage();
            $this->repo->updateFile($file->id, ['status' => 'error', 'error' => mb_substr($message, 0, 220)]);
        }
        $f = $this->repo->file($ws, $file->id);

        return collect($f->toArray())->except(['path', 'content'])->all();
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

    public function purge(): void
    {
        foreach ($this->repo->purgeExpired() as $path) {
            Storage::disk('local')->delete($path);
        }
    }
}
