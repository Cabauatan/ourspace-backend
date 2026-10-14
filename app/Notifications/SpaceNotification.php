<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class SpaceNotification extends Notification
{
    public const TYPES = [
        'incoming_call' => ['Incoming call', 'Your partner is calling. Open OurSpace to answer.', '/chat'],
        'chat_message' => ['New message', 'Your partner sent a message.', '/chat'],
        'task_assigned' => ['Task assigned', 'You have a shared task to check.', '/tasks'],
        'task_completed' => ['Task completed', 'Your partner completed a task.', '/tasks'],
        'calendar_reminder' => ['Calendar reminder', 'A shared event is coming up.', '/calendar'],
        'anniversary' => ['Our anniversary', 'Today is your special day together.', '/calendar'],
        'daily_question' => ['Daily question', 'Take a moment to answer together.', '/questions'],
        'memory_created' => ['New memory', 'Your partner added a memory.', '/memories'],
        'challenge_completed' => ['Challenge completed', 'You completed a couple challenge.', '/challenges'],
    ];

    public function __construct(public string $kind, public ?string $callId = null) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        [$title, $body, $path] = self::TYPES[$this->kind];

        return ['kind' => $this->kind, 'title' => $title, 'body' => $body, 'path' => $path, ...($this->callId ? ['call_id' => $this->callId] : [])];
    }
}
