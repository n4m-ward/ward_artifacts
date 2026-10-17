<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserInvited extends Notification
{
    public function __construct(public string $url) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Você foi convidado para o '.config('app.name'))
            ->greeting("Olá, {$notifiable->name}!")
            ->line('Você recebeu um convite para acessar o '.config('app.name').'.')
            ->action('Definir minha senha', $this->url)
            ->line('O link vale por 7 dias. Se você não esperava este convite, ignore este e-mail.');
    }
}
