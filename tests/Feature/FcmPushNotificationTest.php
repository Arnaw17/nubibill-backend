<?php

namespace Tests\Feature;

use App\Jobs\SendFcmNotificationJob;
use App\Models\User;
use App\Models\UserDeviceToken;
use App\Models\UserDocument;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use Mockery;
use Tests\TestCase;

class FcmPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_register_reassign_and_remove_fcm_token(): void
    {
        $this->postJson('/api/v1/fcm/token', [
            'token' => 'fcm-device-token-123',
        ])->assertStatus(401);

        $userA = $this->createUser('owner-a@example.com', 'USER', 'ACTIVE');
        $userB = $this->createUser('owner-b@example.com', 'USER', 'ACTIVE');
        $tokenA = $userA->createToken('mobile')->plainTextToken;
        $tokenB = $userB->createToken('mobile')->plainTextToken;

        $this->withToken($tokenA)
            ->postJson('/api/v1/fcm/token', [
                'token' => 'fcm-device-token-123',
                'platform' => 'android',
                'device_name' => 'Pixel 8',
                'app_version' => '1.0.0',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.platform', 'android');

        $this->assertDatabaseHas('user_device_tokens', [
            'user_id' => (string) $userA->id,
            'token' => 'fcm-device-token-123',
            'platform' => 'android',
        ]);

        // Same physical phone switches account -> token is reassigned to User B
        auth()->forgetGuards();
        $this->withToken($tokenB)
            ->postJson('/api/v1/fcm/token', [
                'token' => 'fcm-device-token-123',
                'platform' => 'android',
            ])
            ->assertOk();

        $this->assertDatabaseHas('user_device_tokens', [
            'user_id' => (string) $userB->id,
            'token' => 'fcm-device-token-123',
        ]);
        $this->assertSame(1, UserDeviceToken::where('token', 'fcm-device-token-123')->count());

        // Logout deletes only the caller's token
        $this->withToken($tokenB)
            ->deleteJson('/api/v1/fcm/token', [
                'token' => 'fcm-device-token-123',
            ])
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('user_device_tokens', [
            'token' => 'fcm-device-token-123',
        ]);
    }

    public function test_fcm_service_stringifies_data_payload_and_prunes_unregistered_tokens(): void
    {
        $user = $this->createUser('notify@example.com', 'USER', 'ACTIVE');
        UserDeviceToken::create([
            'user_id' => (string) $user->id,
            'token' => 'valid-token-1',
            'platform' => 'android',
        ]);
        UserDeviceToken::create([
            'user_id' => (string) $user->id,
            'token' => 'stale-token-2',
            'platform' => 'android',
        ]);

        $report = MulticastSendReport::withItems([
            SendReport::success(
                MessageTarget::with(MessageTarget::TOKEN, 'valid-token-1'),
                ['name' => 'projects/test/messages/1']
            ),
            SendReport::failure(
                MessageTarget::with(MessageTarget::TOKEN, 'stale-token-2'),
                new NotFound('Requested entity was not found.')
            ),
        ]);

        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(function (CloudMessage $message, array $tokens): bool {
                $serialized = $message->jsonSerialize();
                $data = $serialized['data'] ?? [];

                return $tokens === ['valid-token-1', 'stale-token-2']
                    && $data['amount'] === '1499'
                    && $data['paid'] === 'true'
                    && $data['meta'] === '{"invoice":"INV-1"}';
            })
            ->andReturn($report);

        $this->app->instance(Messaging::class, $messaging);

        $result = app(FcmService::class)->sendToUser(
            $user,
            'Payment Alert',
            'Invoice paid',
            [
                'amount' => 1499,
                'paid' => true,
                'meta' => ['invoice' => 'INV-1'],
            ]
        );

        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['pruned']);

        $this->assertDatabaseHas('user_device_tokens', ['token' => 'valid-token-1']);
        $this->assertDatabaseMissing('user_device_tokens', ['token' => 'stale-token-2']);
    }

    public function test_superuser_notification_endpoint_requires_admin_and_dispatches_topic_push(): void
    {
        $regular = $this->createUser('user@example.com', 'USER', 'ACTIVE');
        $admin = $this->createUser('admin@example.com', 'ADMIN', 'ACTIVE');

        $this->withToken($regular->createToken('app')->plainTextToken)
            ->postJson('/api/v1/superuser/notifications/send', [
                'topic' => 'all_users',
                'title' => 'Notice',
                'body' => 'Hello',
            ])
            ->assertStatus(403);

        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('send')
            ->once()
            ->andReturn(['name' => 'projects/test/messages/topic-1']);
        $this->app->instance(Messaging::class, $messaging);

        auth()->forgetGuards();
        $this->withToken($admin->createToken('super')->plainTextToken)
            ->postJson('/api/v1/superuser/notifications/send', [
                'topic' => 'all_users',
                'title' => 'System Maintenance',
                'body' => 'Scheduled update tonight.',
                'data' => ['target_screen' => 'dashboard'],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.sent', 1);
    }

    public function test_admin_user_activation_and_kyc_review_dispatch_fcm_notification_jobs(): void
    {
        Bus::fake();

        $admin = $this->createUser('reviewer@example.com', 'ADMIN', 'ACTIVE');
        $owner = $this->createUser('applicant@example.com', 'USER', 'INACTIVE');
        $owner->email_verified_at = now();
        $owner->save();

        $aadhaar = UserDocument::create([
            'user_id' => $owner->id,
            'type' => 'AADHAAR',
            'file_path' => 'documents/aadhaar.pdf',
            'status' => 'PENDING',
        ]);
        $pan = UserDocument::create([
            'user_id' => $owner->id,
            'type' => 'PAN',
            'file_path' => 'documents/pan.pdf',
            'status' => 'PENDING',
        ]);

        $token = $admin->createToken('super')->plainTextToken;

        $this->withToken($token)
            ->patchJson("/api/v1/admin/users/{$owner->id}/documents/{$aadhaar->id}", [
                'status' => 'APPROVED',
            ])
            ->assertOk();

        $this->withToken($token)
            ->patchJson("/api/v1/admin/users/{$owner->id}/documents/{$pan->id}", [
                'status' => 'APPROVED',
            ])
            ->assertOk();

        $this->withToken($token)
            ->patchJson("/api/v1/admin/users/{$owner->id}/status", [
                'status' => 'ACTIVE',
            ])
            ->assertOk();

        Bus::assertDispatched(SendFcmNotificationJob::class, 3);
        Bus::assertDispatched(SendFcmNotificationJob::class, function (SendFcmNotificationJob $job) use ($owner): bool {
            return $job->userIds === [(string) $owner->id]
                && $job->title === 'Account Approved'
                && ($job->data['target_screen'] ?? null) === 'dashboard';
        });
    }

    private function createUser(string $email, string $role, string $status): User
    {
        $user = new User([
            'name' => 'Test User',
            'email' => $email,
            'phone' => '9'.str_pad((string) random_int(100000000, 999999999), 9, '0', STR_PAD_LEFT),
            'password' => Hash::make('Secret@123'),
        ]);
        $user->role = $role;
        $user->status = $status;
        $user->save();

        return $user;
    }
}
