<?php

namespace App\Modules\Identity\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Modules\Identity\Services\IdentityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthController extends ApiController
{
    public function __construct(private IdentityService $service) {}

    public function login(Request $r)
    {
        $data = $r->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        return $this->ok($this->service->signIn($r, $data, false));
    }

    public function register(Request $r)
    {
        $data = $r->validate(['name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email', 'max:200', 'unique:users,email'], 'password' => ['required', 'string', 'min:12', 'max:200']]);

        return $this->ok($this->service->signIn($r, $data, true), 201);
    }

    public function logout(Request $r)
    {
        $this->service->signOut($r);

        return $this->ok();
    }

    public function import(Request $r)
    {
        $this->service->import($r);

        return $this->ok();
    }

    public function forgot(Request $r)
    {
        $r->validate(['email' => ['required', 'email']]);
        Password::sendResetLink(['email' => $r->input('email')]);

        return $this->ok(['message' => 'Nếu email đã đăng ký, hướng dẫn khôi phục sẽ được gửi.']);
    }

    public function reset(Request $r)
    {
        $data = $r->validate(['token' => ['required', 'string'], 'email' => ['required', 'email'], 'password' => ['required', 'string', 'min:12', 'confirmed']]);
        $status = Password::reset($data, function ($user, $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
        });
        abort_unless($status === Password::PASSWORD_RESET, 422, 'Liên kết hết hạn hoặc không hợp lệ.');

        return $this->ok();
    }
}
