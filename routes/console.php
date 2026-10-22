<?php

use App\Modules\Identity\Models\User;
use App\Modules\Workspace\Services\FileService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('gehihi:admin {email} {--name=Admin}', function () {
    $email = strtolower($this->argument('email'));
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Email không hợp lệ.');

        return 1;
    }
    $user = User::where('email', $email)->first();
    if (! $user) {
        $password = $this->secret('Mật khẩu (tối thiểu 12 ký tự)');
        if (strlen($password) < 12) {
            $this->error('Mật khẩu quá ngắn.');

            return 1;
        }$user = User::create(['email' => $email, 'name' => $this->option('name'), 'password' => $password]);
    }
    $user->update(['is_admin' => true]);
    $this->info('Đã cấp quyền quản trị cho '.$email.'.');
})->purpose('Tạo/cấp quyền quản trị. Không tự cấp admin cho người đăng ký đầu tiên.');
Artisan::command('gehihi:purge', function () {
    app(FileService::class)->purge();
    $this->info('Đã dọn workspace khách hết hạn và chat tạm thời quá 24 giờ.');
});
Schedule::command('gehihi:purge')->hourly()->withoutOverlapping();
