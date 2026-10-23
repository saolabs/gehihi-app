<?php

namespace App\Modules\Workspace\Jobs;

use App\Modules\Workspace\Services\FileService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Đọc nội dung tệp vừa upload ngoài request: PDF lớn không còn giữ kết nối upload tới khi pdftotext chạy xong. */
class ExtractFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public string $fileId) {}

    public function handle(FileService $files): void
    {
        $files->extract($this->fileId);
    }

    public function failed(?\Throwable $e): void
    {
        app(FileService::class)->markFailed($this->fileId, 'Chưa đọc xong tệp: tác vụ nền bị ngắt hoặc hết thời gian.');
    }
}
