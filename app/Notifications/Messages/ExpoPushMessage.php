<?php

namespace App\Notifications\Messages;

/**
 * One message for the Expo Push API (https://docs.expo.dev/push-notifications/sending-notifications/).
 */
class ExpoPushMessage
{
    /** @var array<string, mixed> */
    protected array $data = [];

    protected ?string $sound = 'default';

    protected string $channelId = 'default';

    protected string $priority = 'high';

    public function __construct(
        protected string $title,
        protected string $body,
    ) {}

    public static function make(string $title, string $body): static
    {
        return new static($title, $body);
    }

    /**
     * Custom payload the app reads when the notification is tapped (at most 4 KiB in total).
     *
     * @param  array<string, mixed>  $data
     */
    public function data(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function sound(?string $sound): static
    {
        $this->sound = $sound;

        return $this;
    }

    public function channelId(string $channelId): static
    {
        $this->channelId = $channelId;

        return $this;
    }

    public function priority(string $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * @param  list<string>  $tokens
     * @return array<string, mixed>
     */
    public function toArray(array $tokens): array
    {
        return array_filter([
            'to' => $tokens,
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data ?: null,
            'sound' => $this->sound,
            'channelId' => $this->channelId,
            'priority' => $this->priority,
        ], fn (mixed $value): bool => $value !== null);
    }
}
