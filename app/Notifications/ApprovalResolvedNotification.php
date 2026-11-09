<?php

namespace App\Notifications;

use App\Notifications\Channels\OneSignalChannel;
use App\Notifications\Messages\OneSignalMessage;
use App\Services\ApprovalFlowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Push to the requester once their request is fully approved or rejected.
 */
class ApprovalResolvedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Model $request,
        public string $requestType,
        public string $status,
        public ?string $reason = null,
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
        $label = ApprovalFlowService::requestTypeLabel($this->requestType);

        if ($this->status === 'approved') {
            $title = "Pengajuan {$label} disetujui";
            $body = "Pengajuan {$label} Anda telah disetujui sepenuhnya.";
        } else {
            $title = "Pengajuan {$label} ditolak";
            $body = filled($this->reason)
                ? "Pengajuan {$label} Anda ditolak: ".Str::limit(trim((string) $this->reason), 140)
                : "Pengajuan {$label} Anda ditolak.";
        }

        return OneSignalMessage::make($title, $body)
            ->data([
                'kind' => 'approval_resolved',
                'request_type' => $this->requestType,
                'request_id' => $this->request->getKey(),
                'status' => $this->status,
            ])
            ->webUrl(url('/admin'));
    }
}
