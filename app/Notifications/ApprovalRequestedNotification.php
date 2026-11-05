<?php

namespace App\Notifications;

use App\Notifications\Channels\ExpoPushChannel;
use App\Notifications\Messages\ExpoPushMessage;
use App\Services\ApprovalFlowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;

/**
 * Push to an approver when a request reaches a stage they may act on.
 */
class ApprovalRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Model $request,
        public string $requestType,
        public string $workerName,
        public int $stepOrder,
        public bool $isNewRequest = false,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return method_exists($notifiable, 'deviceTokens') && $notifiable->deviceTokens()->exists()
            ? [ExpoPushChannel::class]
            : [];
    }

    public function toExpoPush(object $notifiable): ExpoPushMessage
    {
        $label = ApprovalFlowService::requestTypeLabel($this->requestType);

        $title = $this->isNewRequest
            ? 'Pengajuan baru menunggu persetujuan Anda'
            : "Pengajuan menunggu persetujuan tahap {$this->stepOrder}";

        return ExpoPushMessage::make($title, "Pengajuan {$label} dari {$this->workerName} menunggu persetujuan Anda.")
            ->data([
                'kind' => 'approval_requested',
                'request_type' => $this->requestType,
                'request_id' => $this->request->getKey(),
                'step_order' => $this->stepOrder,
            ]);
    }
}
