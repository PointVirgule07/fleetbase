<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class OrderSocketEvent implements ShouldBroadcastNow
{
    use SerializesModels;

    public $order;
    public $channelName;
    public $eventName;

    public function __construct($order, $channelName, $eventName = 'order.created')
    {
        $this->order = $order;
        $this->channelName = $channelName;
        $this->eventName = $eventName;
    }

    public function broadcastOn()
    {
        return new Channel($this->channelName);
    }

    public function broadcastAs()
    {
        return $this->eventName;
    }
}
