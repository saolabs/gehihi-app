<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Notifications\Notifiable;

class User extends \Illuminate\Foundation\Auth\User
{
    use HasUuids, Notifiable;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected $attributes = ['is_admin' => false];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_admin' => 'boolean'];
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPassword($token));
    }
}
