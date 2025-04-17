<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TenantCreated extends Notification
{
    use Queueable;

    protected $tenant;
    protected $creator;

    public function __construct($tenant, $creator)
    {
        $this->tenant = $tenant;
        $this->creator = $creator;
    }

    public function via($notifiable)
    {
        return ['database', 'mail'];
    }

    public function toDatabase($notifiable)
    {
        return [
            'tenant_id' => $this->tenant->id,
            'name' => $this->tenant->name,
            'domain' => $this->tenant->domain,
            'message' => "Tenant “{$this->tenant->name}” created by {$this->creator->name}.",
        ];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('New Tenant Created')
            ->greeting("Hello {$notifiable->name},")
            ->line("A new tenant “{$this->tenant->name}” (domain: {$this->tenant->domain}) has been created.")
            ->line("Created by: {$this->creator->name} ({$this->creator->email})")
            ->line("At: {$this->tenant->created_at->toDateTimeString()}")
            ->action('Manage Tenant', url("/tenants/{$this->tenant->id}"));
    }

    public function toArray($notifiable)
    {
        return [];
    }
}
