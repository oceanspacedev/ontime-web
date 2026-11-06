<?php

namespace App\Notifications\Messages;

/**
 * One push for the OneSignal Create Notification API, sent to users by their OnTime user id
 * (OneSignal external id, set by the app with OneSignal.login).
 */
class OneSignalMessage
{
    /** @var array<string, mixed> */
    protected array $data = [];

    public function __construct(
        protected string $title,
        protected string $body,
    ) {}

    public static function make(string $title, string $body): static
    {
        return new static($title, $body);
    }

    /**
     * Payload the app reads when the push is tapped (OneSignal "additional data").
     *
     * @param  array<string, mixed>  $data
     */
    public function data(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * @param  list<string>  $externalIds
     * @return array<string, mixed>
     */
    public function toArray(string $appId, array $externalIds): array
    {
        // "en" is required by OneSignal; the app's texts are Indonesian, so both carry the same text.
        return array_filter([
            'app_id' => $appId,
            'target_channel' => 'push',
            'include_aliases' => ['external_id' => $externalIds],
            'headings' => ['en' => $this->title, 'id' => $this->title],
            'contents' => ['en' => $this->body, 'id' => $this->body],
            'data' => $this->data ?: null,
            'priority' => 10,
        ], fn (mixed $value): bool => $value !== null);
    }
}
