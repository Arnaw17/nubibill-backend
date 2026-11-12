<?php

namespace Tests\Feature;

use App\Models\AppRelease;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OtaReleaseSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_superuser_release_endpoints_require_active_admin(): void
    {
        $this->getJson('/api/v1/superuser/releases')
            ->assertStatus(401)
            ->assertJson(['code' => 'UNAUTHORIZED']);

        $regular = $this->createUser('user@example.com', 'USER');
        $token = $regular->createToken('user')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/superuser/releases')
            ->assertStatus(403)
            ->assertJson(['code' => 'FORBIDDEN']);
    }

    public function test_superuser_can_publish_toggle_recall_and_delete_releases(): void
    {
        Storage::fake('private');

        $admin = $this->createUser('admin@example.com', 'ADMIN');
        $token = $admin->createToken('superuser')->plainTextToken;

        $zipV1 = UploadedFile::fake()->createWithContent('nubibill-1.2.0.zip', 'fake-zip-v120');
        $zipV2 = UploadedFile::fake()->createWithContent('nubibill-1.10.0.zip', 'fake-zip-v1100');

        $res1 = $this->withToken($token)->post('/api/v1/superuser/releases', [
            'version' => '1.2.0',
            'channel' => 'stable',
            'platform' => 'win32-x64',
            'is_mandatory' => '0',
            'release_notes' => 'Optional patch',
            'package_file' => $zipV1,
        ]);
        $res1->assertStatus(201)->assertJsonPath('data.release.version', '1.2.0');

        $res2 = $this->withToken($token)->post('/api/v1/superuser/releases', [
            'version' => '1.10.0',
            'channel' => 'stable',
            'platform' => 'win32-x64',
            'is_mandatory' => '1',
            'min_supported_version' => '1.0.0',
            'database_migration_required' => '1',
            'target_db_version' => '14',
            'release_notes' => 'Major release',
            'package_file' => $zipV2,
        ]);
        $res2->assertStatus(201);

        $idV2 = $res2->json('data.release.id');
        $storedPath = AppRelease::findOrFail($idV2)->file_path;
        Storage::disk('private')->assertExists($storedPath);

        // SemVer sorting: 1.10.0 must come before 1.2.0
        $list = $this->withToken($token)->getJson('/api/v1/superuser/releases');
        $list->assertOk()
            ->assertJsonPath('data.releases.0.version', '1.10.0')
            ->assertJsonPath('data.releases.1.version', '1.2.0')
            ->assertJsonPath('data.summary.latestActiveVersion', '1.10.0');

        // Toggle mandatory off
        $this->withToken($token)
            ->postJson("/api/v1/superuser/releases/{$idV2}/toggle-mandatory")
            ->assertOk()
            ->assertJsonPath('data.release.is_mandatory', false);

        // Recall faulty release 1.10.0
        $this->withToken($token)
            ->postJson("/api/v1/superuser/releases/{$idV2}/recall", ['reason' => 'Crash on invoice print'])
            ->assertOk()
            ->assertJsonPath('data.release.is_recalled', true)
            ->assertJsonPath('data.release.is_active', false);

        // Client on recalled 1.10.0 gets rollback target 1.2.0
        $checkRecalled = $this->getJson('/api/v1/updates/check?version=1.10.0&platform=win32-x64&channel=stable');
        $checkRecalled->assertOk()
            ->assertJsonPath('data.is_recalled', true)
            ->assertJsonPath('data.rollback_recommended', true)
            ->assertJsonPath('data.latest_version', '1.2.0');

        // Delete release removes file from private disk
        $this->withToken($token)
            ->deleteJson("/api/v1/superuser/releases/{$idV2}")
            ->assertOk();

        Storage::disk('private')->assertMissing($storedPath);
    }

    public function test_public_check_enforces_intermediate_mandatory_and_works_in_maintenance(): void
    {
        Storage::fake('private');

        $r1 = $this->seedRelease('1.0.0', false);
        $r2 = $this->seedRelease('1.5.0', true);
        $r3 = $this->seedRelease('2.0.0', false);

        // Enable maintenance mode to verify OTA updates remain reachable
        DB::table('app_config')->insert([
            'id' => 'system',
            'document_path' => 'app_config/system',
            'is_deleted' => 0,
            'payload' => json_encode(['fields' => ['isActive' => false, 'maintenanceMessage' => 'Maintenance']]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Client on 1.0.0 upgrading to 2.0.0 must be forced because 1.5.0 is mandatory
        $this->getJson('/api/v1/updates/check?version=1.0.0+1&platform=win32-x64')
            ->assertOk()
            ->assertJsonPath('data.update_available', true)
            ->assertJsonPath('data.latest_version', '2.0.0')
            ->assertJsonPath('data.is_mandatory', true);

        // Client on 1.6.0 upgrading to 2.0.0 gets optional update
        $this->getJson('/api/v1/updates/check?version=1.6.0&platform=win32-x64')
            ->assertOk()
            ->assertJsonPath('data.update_available', true)
            ->assertJsonPath('data.latest_version', '2.0.0')
            ->assertJsonPath('data.is_mandatory', false);

        // Public download increments download_count and returns SHA-256 header
        $download = $this->get("/api/v1/updates/download/{$r3->id}");
        $download->assertOk();
        $download->assertHeader('X-Checksum-SHA256', $r3->checksum_sha256);
        $this->assertSame(1, $r3->fresh()->download_count);

        // Inactive release download is blocked
        $r1->update(['is_active' => false]);
        $this->getJson("/api/v1/updates/download/{$r1->id}")
            ->assertStatus(403)
            ->assertJson(['code' => 'RELEASE_UNAVAILABLE']);
    }

    private function seedRelease(string $version, bool $isMandatory): AppRelease
    {
        $content = "payload-{$version}";
        $path = "releases/nubibill-{$version}.zip";
        Storage::disk('private')->put($path, $content);

        return AppRelease::create([
            'version' => $version,
            'channel' => 'stable',
            'platform' => 'win32-x64',
            'is_mandatory' => $isMandatory,
            'database_migration_required' => false,
            'target_db_version' => 1,
            'release_notes' => "Release {$version}",
            'file_path' => $path,
            'file_name' => "nubibill-{$version}.zip",
            'file_size' => strlen($content),
            'checksum_sha256' => hash('sha256', $content),
            'is_active' => true,
            'is_recalled' => false,
            'published_at' => now(),
        ]);
    }

    private function createUser(string $email, string $role, string $status = 'ACTIVE'): User
    {
        $user = new User;
        $user->forceFill([
            'name' => ucfirst(strtok($email, '@')),
            'email' => $email,
            'phone' => (string) random_int(9000000000, 9999999999),
            'password' => Hash::make('Password123!'),
            'role' => $role,
            'status' => $status,
            'email_verified_at' => now(),
            'activated_at' => now(),
        ]);
        $user->save();

        return $user;
    }
}
