<?php

namespace App\Jobs;

use App\Services\FcmService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendFcmNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  list<string>|null  $userIds
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly ?array $userIds,
        public readonly ?string $topic,
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
        public readonly ?string $businessId = null,
    ) {}

    /**
     * @param  list<string>  $userIds
     * @param  array<string, mixed>  $data
     */
    public static function forUsers(array $userIds, string $title, string $body, array $data = []): self
    {
        return new self($userIds, null, $title, $body, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function forBusiness(string $businessId, string $title, string $body, array $data = []): self
    {
        return new self(null, null, $title, $body, $data, $businessId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function forTopic(string $topic, string $title, string $body, array $data = []): self
    {
        return new self(null, $topic, $title, $body, $data);
    }

    public function handle(FcmService $fcmService): void
    {
        try {
            if ($this->topic !== null && $this->topic !== '') {
                $fcmService->sendToTopic($this->topic, $this->title, $this->body, $this->data);

                return;
            }

            if ($this->businessId !== null && $this->businessId !== '') {
                $fcmService->sendToBusiness($this->businessId, $this->title, $this->body, $this->data);

                return;
            }

            if ($this->userIds !== null && $this->userIds !== []) {
                $fcmService->sendToUsers($this->userIds, $this->title, $this->body, $this->data);
            }
        } catch (\Throwable $e) {
            Log::error('FCM notification job failed: '.$e->getMessage(), [
                'topic' => $this->topic,
                'business_id' => $this->businessId,
                'user_count' => is_array($this->userIds) ? count($this->userIds) : 0,
                'title' => $this->title,
            ]);
        }
    }
}
