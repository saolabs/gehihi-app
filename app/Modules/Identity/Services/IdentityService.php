<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Repositories\IdentityRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class IdentityService
{
    public function __construct(private IdentityRepositoryInterface $identities) {}

    public function signIn(Request $request, array $data, bool $register): array
    {
        if ($register) {
            $user = $this->identities->register(['name' => $data['name'], 'email' => mb_strtolower($data['email']), 'password' => $data['password']]);
            Auth::login($user);
        } elseif (! Auth::attempt(['email' => mb_strtolower($data['email']), 'password' => $data['password']])) {
            throw ValidationException::withMessages(['email' => 'Email hoặc mật khẩu không đúng.']);
        }
        $request->session()->regenerate();

        return ['user' => $request->user(), 'import_available' => (bool) $request->session()->get('guest_workspace_id')];
    }

    public function import(Request $request): void
    {
        abort_unless($request->user(), 401);
        $id = $request->session()->get('guest_workspace_id');
        $token = $request->session()->get('guest_token');
        if (! $id || ! $token || ! $this->identities->guestById($id, hash('sha256', $token))) {
            return;
        }
        $this->identities->import($id, $this->identities->account($request->user()->id)->id);
        $request->session()->forget(['guest_workspace_id', 'guest_token']);
    }

    public function signOut(Request $request): void
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
