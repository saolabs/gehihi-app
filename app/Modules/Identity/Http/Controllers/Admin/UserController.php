<?php

namespace App\Modules\Identity\Http\Controllers\Admin;

use App\Http\Controllers\AdminController;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\UserDirectory;
use Illuminate\Http\Request;

class UserController extends AdminController
{
    public function __construct(private UserDirectory $directory) {}

    public function index(Request $r)
    {
        $q = trim((string) $r->query('q', ''));

        return $this->response(['q' => $q, 'users' => $this->directory->users($q, $r->user()->id)]);
    }

    public function update(Request $r, string $id)
    {
        $data = $r->validate(['is_admin' => ['required', 'boolean']]);
        abort_if($id === $r->user()->id && ! $data['is_admin'], 422, 'Không thể tự thu quyền quản trị của chính bạn.');
        User::findOrFail($id)->update($data);

        return $this->result(['users' => $this->directory->users(trim((string) $r->input('q', '')), $r->user()->id)]);
    }
}
