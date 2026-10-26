<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SuperUserControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_superuser_owner_endpoint_requires_an_active_administrator(): void
    {
        $response = $this->getJson('/api/v1/superuser/owners');

        $response->assertStatus(401)->assertJson(['code' => 'UNAUTHORIZED']);

        $regular = $this->createUser('regular@example.com', 'USER');
        $token = $regular->createToken('regular')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/superuser/owners')
            ->assertStatus(403)
            ->assertJson(['code' => 'FORBIDDEN']);
    }

    public function test_superuser_lists_owner_documents_and_admin_can_activate_from_api(): void
    {
        $admin = $this->createUser('admin@example.com', 'ADMIN', 'ACTIVE');
        $owner = $this->createUser('owner@example.com', 'USER', 'INACTIVE');
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
        $token = $admin->createToken('superuser')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/superuser/owners?status=PENDING')
            ->assertOk()
            ->assertJsonPath('data.owners.0.id', (string) $owner->id)
            ->assertJsonCount(2, 'data.owners.0.documents');

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
            ->patchJson("/api/v1/admin/users/{$owner->id}/status", ['status' => 'ACTIVE'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertTrue($owner->fresh()->isActive());
    }

    public function test_terminated_session_cannot_be_revived_by_heartbeat(): void
    {
        $admin = $this->createUser('admin2@example.com', 'ADMIN', 'ACTIVE');
        $owner = $this->createUser('owner2@example.com', 'USER', 'ACTIVE');
        $owner->email_verified_at = now();
        $owner->save();
        $ownerToken = $owner->createToken('owner')->plainTextToken;
        $adminToken = $admin->createToken('superuser')->plainTextToken;

        $this->withToken($ownerToken)
            ->postJson('/api/v1/telemetry/login', [
                'session_id' => 'session-owner-2',
                'user_id' => 'spoofed-user',
            ])
            ->assertOk();

        $this->assertSame(
            (string) $owner->id,
            (string) DB::table('app_user_sessions')->where('session_id', 'session-owner-2')->value('user_id')
        );

        auth()->forgetGuards();

        $terminate = $this->withToken($adminToken)
            ->postJson('/api/v1/superuser/sessions/session-owner-2/terminate');
        $terminate->assertOk();

        auth()->forgetGuards();
        $heartbeat = $this->withToken($ownerToken)
            ->postJson('/api/v1/telemetry/heartbeat', [
                'session_id' => 'session-owner-2',
            ]);
        $heartbeat->assertOk()->assertJsonPath('data.is_terminated', true);

        $this->assertSame(
            'TERMINATED',
            DB::table('app_user_sessions')->where('session_id', 'session-owner-2')->value('status')
        );
    }

    private function createUser(string $email, string $role, string $status = 'ACTIVE'): User
    {
        $user = new User([
            'name' => ucfirst(strtok($email, '@')),
            'email' => $email,
            'phone' => (string) random_int(9000000000, 9999999999),
            'password' => Hash::make('Password123!'),
            'role' => $role,
            'email_verified_at' => now(),
        ]);
        $user->status = $status;
        $user->save();

        return $user;
    }
}
