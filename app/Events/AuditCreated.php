<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;
use Illuminate\Broadcasting\InteractsWithSockets;
use OwenIt\Auditing\Models\Audit;

class AuditCreated implements ShouldBroadcast
{
    use InteractsWithSockets, SerializesModels;

    public $audit;

    public function __construct(Audit $audit)
    {
        $this->audit = $audit;
    }

    public function broadcastOn()
    {
        return new Channel('audits'); // public channel
    }

    public function broadcastWith()
    {
        return [
            'audit' => $this->audit->toArray(),
        ];
    }
}
