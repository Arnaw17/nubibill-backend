<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $adminEmail = env('INITIAL_ADMIN_EMAIL', 'admin@nubibilling.com');
        $adminPassword = env('INITIAL_ADMIN_PASSWORD');

        if (! $adminPassword) {
            if (app()->environment('production')) {
                // In production, do not create admin with known default password
                return;
            }
            $adminPassword = 'AdminPass123!';
        }

        // Admin user for review and system management
        $admin = User::firstOrNew(['email' => $adminEmail]);
        if (! $admin->exists) {
            $admin->id = (string) Str::uuid();
            $admin->name = 'System Administrator';
            $admin->email = $adminEmail;
            $admin->phone = '9999999999';
            $admin->password = Hash::make($adminPassword);
            $admin->role = 'ADMIN';
            $admin->status = 'ACTIVE';
            $admin->email_verified_at = now();
            $admin->activated_at = now();
            $admin->save();
        }

        // Default store owner / developer account (John Doe)
        $john = User::firstOrNew(['email' => 'john@example.com']);
        if (! $john->exists) {
            $john->id = '01a168d5-cd65-734c-b20d-34941ef36c8b';
            $john->name = 'John Doe';
            $john->email = 'john@example.com';
            $john->phone = '9876543210';
            $john->password = Hash::make('Secret123!');
            $john->role = 'ADMIN';
            $john->status = 'ACTIVE';
            $john->business_id = 'BUS-BFMC378UAMES';
            $john->email_verified_at = now();
            $john->activated_at = now();
            $john->save();
        }

        // Seed system app_config
        if (\Illuminate\Support\Facades\Schema::hasTable('app_config')) {
            \Illuminate\Support\Facades\DB::table('app_config')->updateOrInsert(
                ['id' => 'system'],
                [
                    'document_path' => 'app_config/system',
                    'is_active' => true,
                    'status' => 'active',
                    'is_deleted' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                    'payload' => json_encode([
                        '_documentId' => 'system',
                        '_documentPath' => 'app_config/system',
                        'fields' => [
                            'isActive' => true,
                            'status' => 'active',
                            'maintenanceMessage' => null,
                        ],
                    ]),
                ]
            );
        }

        // Seed John Hardware Store and its 3 outlets if outlets table exists
        if (\Illuminate\Support\Facades\Schema::hasTable('businesses')) {
            \Illuminate\Support\Facades\DB::table('businesses')->updateOrInsert(
                ['id' => 'BUS-BFMC378UAMES'],
                [
                    'name' => 'John Hardware Store 502 Main Branch',
                    'status' => 'active',
                    'is_deleted' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                    'payload' => json_encode([
                        '_documentId' => 'BUS-BFMC378UAMES',
                        '_documentPath' => 'businesses/BUS-BFMC378UAMES',
                        'fields' => [
                            'businessId' => 'BUS-BFMC378UAMES',
                            'ownerUid' => '01a168d5-cd65-734c-b20d-34941ef36c8b',
                            'tenantId' => '01a168d5-cd65-734c-b20d-34941ef36c8b',
                            'businessName' => 'John Hardware Store 502 Main Branch',
                            'normalizedBusinessName' => 'john hardware store 502 main branch',
                            'businessTypeId' => 'BT-HARDWARE',
                            'businessTypeName' => 'Hardware',
                            'email' => 'john@example.com',
                            'phone' => '9876543210',
                            'address' => 'Main Branch',
                            'isActive' => true,
                            'status' => 'active',
                        ],
                    ]),
                ]
            );
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('outlets')) {
            $nowIso = now()->toISOString();
            $nowDb = now();
            $outlets = [
                [
                    'id' => 'OUT-318DJT3737PW',
                    'name' => 'John Hardware Store 502 Main Branch',
                    'code' => 'MAIN',
                    'typeId' => 'BST-CONSTRUCTION-HARDWARE',
                    'typeName' => 'Main Outlet',
                    'address' => '502 Main Branch, Market Road',
                    'phone' => '9876543210',
                    'isMain' => true,
                ],
                [
                    'id' => 'OUT-08RTINTJVR0B',
                    'name' => 'John Hardware Store Branch 2',
                    'code' => 'BR-02',
                    'typeId' => 'BST-CONSTRUCTION-HARDWARE',
                    'typeName' => 'Branch 2 Outlet',
                    'address' => 'Industrial Area, Phase 2',
                    'phone' => '9876543211',
                    'isMain' => false,
                ],
                [
                    'id' => 'OUT-JTXOCH5ZCVN8',
                    'name' => 'John Hardware Store Branch 3',
                    'code' => 'BR-03',
                    'typeId' => 'BST-CONSTRUCTION-HARDWARE',
                    'typeName' => 'Branch 3 Outlet',
                    'address' => 'City Center Complex, Sector 4',
                    'phone' => '9876543212',
                    'isMain' => false,
                ],
            ];

            foreach ($outlets as $o) {
                \Illuminate\Support\Facades\DB::table('outlets')->updateOrInsert(
                    ['id' => $o['id']],
                    [
                        'document_path' => 'outlets/' . $o['id'],
                        'business_id' => 'BUS-BFMC378UAMES',
                        'outlet_id' => $o['id'],
                        'status' => 'active',
                        'is_deleted' => 0,
                        'created_at' => $nowDb,
                        'updated_at' => $nowDb,
                        'payload' => json_encode([
                            '_documentId' => $o['id'],
                            '_documentPath' => 'outlets/' . $o['id'],
                            'fields' => [
                                'outletId' => $o['id'],
                                'businessId' => 'BUS-BFMC378UAMES',
                                'ownerUid' => '01a168d5-cd65-734c-b20d-34941ef36c8b',
                                'tenantId' => '01a168d5-cd65-734c-b20d-34941ef36c8b',
                                'outletName' => $o['name'],
                                'outletCode' => $o['code'],
                                'outletTypeId' => $o['typeId'],
                                'outletTypeName' => $o['typeName'],
                                'address' => $o['address'],
                                'contactNumber' => $o['phone'],
                                'isMainOutlet' => $o['isMain'],
                                'enableGST' => true,
                                'enableInventory' => true,
                                'isActive' => true,
                                'status' => 'active',
                                'createdAt' => $nowIso,
                                'updatedAt' => $nowIso,
                            ],
                        ]),
                    ]
                );
            }
        }

        $this->call(JohnHardwareDataSeeder::class);
    }
}
