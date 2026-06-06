<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WebRTCSignal implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $type,
        public mixed  $data,
        public int    $fromUserId,
        public int    $toUserId
    ) {}

    public function broadcastOn(): array
    {
        $channelId = collect([$this->fromUserId, $this->toUserId])->sort()->join('-');
        return [new PrivateChannel("call.{$channelId}")];
    }

    public function broadcastAs(): string
    {
        return 'webrtc.signal';
    }
}