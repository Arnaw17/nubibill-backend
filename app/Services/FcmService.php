<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserDeviceToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FcmService
{
    public const string DEFAULT_ANDROID_CHANNEL = 'high_importance_channel';

    private ?Messaging $messaging = null;

    public function setMessaging(?Messaging $messaging): void
    {
        $this->messaging = $messaging;
    }

    /**
     * Format custom data payload so every value is a string, as required by FCM.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    public function formatData(array $data): array
    {
        $formatted = [];

        foreach ($data as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $encoded = json_encode($value);
                $formatted[(string) $key] = $encoded === false ? '' : $encoded;
            } elseif (is_bool($value)) {
                $formatted[(string) $key] = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $formatted[(string) $key] = '';
            } else {
                $formatted[(string) $key] = (string) $value;
            }
        }

        return $formatted;
    }

    /**
     * Send a push notification to all registered devices of a single user.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function sendToUser(string|User $user, string $title, string $body, array $data = []): array
    {
        $userId = $user instanceof User ? (string) $user->id : $user;

        return $this->sendToUsers([$userId], $title, $body, $data);
    }

    /**
     * Send a push notification to all registered devices for a list of user IDs.
     *
     * @param  list<string>  $userIds
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function sendToUsers(array $userIds, string $title, string $body, array $data = []): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $userIds))));
        if ($ids === []) {
            return ['sent' => 0, 'failed' => 0, 'pruned' => 0, 'skipped' => true, 'reason' => 'No target users'];
        }

        $tokens = UserDeviceToken::query()
            ->whereIn('user_id', $ids)
            ->pluck('token')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($tokens === []) {
            return ['sent' => 0, 'failed' => 0, 'pruned' => 0, 'skipped' => true, 'reason' => 'No registered device tokens'];
        }

        return $this->sendToTokens($tokens, $title, $body, $data);
    }

    /**
     * Send a push notification to all registered devices for users belonging to a business.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function sendToBusiness(string $businessId, string $title, string $body, array $data = []): array
    {
        $userIds = $this->resolveBusinessUserIds($businessId);

        return $this->sendToUsers($userIds, $title, $body, $data);
    }

    /**
     * Resolve user IDs (owner and assigned users) associated with a business ID.
     *
     * @return list<string>
     */
    public function resolveBusinessUserIds(string $businessId): array
    {
        $userIds = User::query()
            ->where('business_id', $businessId)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $businesses = DB::table('businesses')
            ->where('id', $businessId)
            ->where('is_deleted', 0)
            ->get();

        foreach ($businesses as $row) {
            if (! empty($row->owner_uid)) {
                $userIds[] = (string) $row->owner_uid;
            }
            $payload = json_decode((string) ($row->payload ?? ''), true) ?: [];
            $fields = $payload['fields'] ?? [];
            $ownerUid = $fields['ownerUid'] ?? null;
            if (is_array($ownerUid)) {
                $ownerUid = $ownerUid['stringValue'] ?? null;
            }
            if (is_string($ownerUid) && $ownerUid !== '') {
                $userIds[] = $ownerUid;
            }
        }

        return array_values(array_unique(array_filter($userIds)));
    }

    /**
     * Send a push notification to explicit device registration tokens and prune stale tokens.
     *
     * @param  list<string>  $tokens
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): array
    {
        $tokens = array_values(array_unique(array_filter(array_map('strval', $tokens))));
        if ($tokens === []) {
            return ['sent' => 0, 'failed' => 0, 'pruned' => 0, 'skipped' => true, 'reason' => 'No device tokens'];
        }

        $messaging = $this->resolveMessaging();
        if (! $messaging) {
            return ['sent' => 0, 'failed' => 0, 'pruned' => 0, 'skipped' => true, 'reason' => 'Firebase credentials not configured'];
        }

        $message = $this->buildBaseMessage($title, $body, $data);
        $sent = 0;
        $failed = 0;
        $staleTokens = [];

        foreach (array_chunk($tokens, 500) as $chunk) {
            $report = $messaging->sendMulticast($message, $chunk);
            $sent += $report->successes()->count();
            $failed += $report->failures()->count();

            $staleTokens = array_merge(
                $staleTokens,
                $report->unknownTokens(),
                $report->invalidTokens()
            );
        }

        $pruned = 0;
        $staleTokens = array_values(array_unique($staleTokens));
        if ($staleTokens !== []) {
            $pruned = UserDeviceToken::query()->whereIn('token', $staleTokens)->delete();
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'pruned' => $pruned,
            'skipped' => false,
        ];
    }

    /**
     * Send a push notification to an FCM topic (e.g. "all_users").
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function sendToTopic(string $topic, string $title, string $body, array $data = []): array
    {
        $messaging = $this->resolveMessaging();
        if (! $messaging) {
            return ['sent' => 0, 'skipped' => true, 'reason' => 'Firebase credentials not configured'];
        }

        $message = $this->buildBaseMessage($title, $body, $data)->withTopic($topic);
        $result = $messaging->send($message);

        return [
            'sent' => 1,
            'skipped' => false,
            'details' => $result,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function buildBaseMessage(string $title, string $body, array $data = []): CloudMessage
    {
        return CloudMessage::new()
            ->withNotification(Notification::create($title, $body))
            ->withData($this->formatData($data))
            ->withAndroidConfig(AndroidConfig::fromArray([
                'priority' => 'high',
                'notification' => [
                    'channel_id' => self::DEFAULT_ANDROID_CHANNEL,
                    'sound' => 'default',
                ],
            ]))
            ->withApnsConfig(ApnsConfig::fromArray([
                'headers' => [
                    'apns-priority' => '10',
                ],
                'payload' => [
                    'aps' => [
                        'sound' => 'default',
                    ],
                ],
            ]));
    }

    private function resolveMessaging(): ?Messaging
    {
        if ($this->messaging !== null) {
            return $this->messaging;
        }

        if (app()->resolved(Messaging::class)) {
            $this->messaging = app(Messaging::class);

            return $this->messaging;
        }

        if (! $this->hasCredentials()) {
            Log::warning('FCM skipped: Firebase service account credentials are not configured or missing.');

            return null;
        }

        $this->messaging = app(Messaging::class);

        return $this->messaging;
    }

    private function hasCredentials(): bool
    {
        $project = (string) config('firebase.default', 'app');
        $configured = config("firebase.projects.{$project}.credentials");

        if (is_array($configured)) {
            return $configured !== [];
        }

        if (is_string($configured) && trim($configured) !== '') {
            if (is_file($configured)) {
                return true;
            }

            $fromBase = base_path($configured);

            return is_file($fromBase);
        }

        $googleEnv = getenv('GOOGLE_APPLICATION_CREDENTIALS');

        return is_string($googleEnv) && $googleEnv !== '' && is_file($googleEnv);
    }
}
