<?php

namespace App\Notifications;

use App\Notifications\Channels\OneSignalChannel;
use App\Notifications\Messages\OneSignalMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A reminder about today's shift: it is about to start, check-in is missing, it is time to leave,
 * or check-out is missing. Coordinators get one about people who have not checked in.
 */
class AttendanceReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $title,
        public string $body,
        public array $data,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return OneSignalChannel::isConfigured() ? [OneSignalChannel::class] : [];
    }

    public function toOneSignal(object $notifiable): OneSignalMessage
    {
        $page = ($this->data['kind'] ?? null) === 'team_missing' ? '/admin' : '/admin/absen-hari-ini';

        return OneSignalMessage::make($this->title, $this->body)->data($this->data)->webUrl(url($page));
    }
}
