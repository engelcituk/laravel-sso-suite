<?php

declare(strict_types=1);

namespace LaravelSso\Server\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class CentralPasswordReset extends Notification
{
    public function __construct(#[\SensitiveParameter] public readonly string $token, public readonly string $email, public readonly int $minutes) {}

    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function url(): string
    {
        return config('sso-server.issuer').'/sso/auth/reset-password/'.rawurlencode($this->token).'?'.http_build_query(['email' => $this->email]);
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Restablece tu contraseña central')
            ->line('Se solicitó establecer una contraseña para tu cuenta central.')
            ->action('Establecer contraseña', $this->url())
            ->line('Este enlace caduca en '.$this->minutes.' minutos y sólo puede utilizarse una vez.')
            ->line('Si no solicitaste este cambio, puedes ignorar este mensaje.');
    }
}
