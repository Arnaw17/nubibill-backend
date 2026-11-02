<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BusinessCreationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_creation_stores_document_columns_and_payload(): void
    {
        $user = User::factory()->create([
            'email' => 'business-schema@example.com',
            'phone' => '9998887776',
            'password' => Hash::make('Password123!'),
            'role' => 'ADMIN',
            'status' => 'ACTIVE',
        ]);
        DB::table('business_types')->insert([
            'id' => 'BT-HARDWARE',
            'status' => 'active',
            'is_deleted' => 0,
            'payload' => json_encode(['fields' => [
                'id' => 'BT-HARDWARE',
                'name' => 'Hardware',
                'isActive' => true,
            ]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->withoutMiddleware()->postJson('/api/v1/businesses', [
            'business_name' => 'Schema Test Business',
            'business_type_id' => 'BT-HARDWARE',
            'email' => 'schema-test@example.com',
            'phone' => '9876543210',
            'address' => '123 Test Street',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.business.businessTypeId', 'BT-HARDWARE')
            ->assertJsonPath('data.business.businessTypeName', 'Hardware');

        $businessId = $response->json('data.business.businessId');
        $this->assertDatabaseHas('businesses', [
            'id' => $businessId,
            'business_id' => $businessId,
            'outlet_id' => null,
            'document_path' => "businesses/{$businessId}",
        ]);

        $payload = json_decode((string) DB::table('businesses')->where('id', $businessId)->value('payload'), true);
        $this->assertSame($businessId, $payload['_documentId']);
        $this->assertSame("businesses/{$businessId}", $payload['_documentPath']);
        $this->assertSame('Schema Test Business', $payload['fields']['businessName']);
    }
}
