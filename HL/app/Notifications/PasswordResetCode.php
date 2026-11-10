<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetCode extends Notification
{
    public function __construct(public readonly string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your HikeVista password reset code')
            ->greeting('Password reset requested')
            ->line("Your six-digit verification code is: **{$this->code}**")
            ->action('Enter your code', rtrim((string) config('app.frontend_url'), '/').'/reset-password')
            ->line('This code expires in 10 minutes. If you did not request a password reset, you can ignore this email.');
    }
}
