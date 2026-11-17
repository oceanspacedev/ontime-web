<?php

namespace App\Notifications;

use App\Models\ShiftSwapRequest;
use App\Notifications\Channels\OneSignalChannel;
use App\Notifications\Messages\OneSignalMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Push to a worker taking part in a Tukar Shift: the colleague is asked, or the requester hears the colleague's
 * answer, or either hears the swap was cancelled or applied.
 */
class ShiftSwapUpdatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ShiftSwapRequest $swap,
        public string $event,
        public string $title,
        public string $body,
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
        return OneSignalMessage::make($this->title, $this->body)
            ->data([
                'kind' => 'shift_swap',
                'event' => $this->event,
                'request_type' => 'shift_swap',
                'request_id' => $this->swap->getKey(),
                'status' => $this->swap->status,
            ]);
    }
}
