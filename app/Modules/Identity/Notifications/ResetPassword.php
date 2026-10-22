<?php

namespace App\Modules\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class ResetPassword extends \Illuminate\Auth\Notifications\ResetPassword
{
    public function toMail($notifiable)
    {
        return (new MailMessage)->subject('Đặt lại mật khẩu Gehihi')->line('Liên kết đặt lại mật khẩu có hiệu lực trong 60 phút.')->action('Đặt lại mật khẩu', rtrim(config('app.url'), '/').'/?reset_token='.urlencode($this->token).'&email='.urlencode($notifiable->email));
    }
}
