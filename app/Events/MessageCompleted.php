<?php

namespace App\Events;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Message      $message,
        public readonly Conversation $conversation,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("ticket.{$this->conversation->ticket_id}"),
            new PrivateChannel("user.{$this->conversation->user_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.completed';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => [
                'uuid'         => $this->message->uuid,
                'role'         => $this->message->role,
                'content'      => $this->message->content,
                'model_used'   => $this->message->model_used,
                'total_tokens' => $this->message->total_tokens,
                'created_at'   => $this->message->created_at->toIso8601String(),
            ],
        ];
    }
}
