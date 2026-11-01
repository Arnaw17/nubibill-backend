<?php

namespace Database\Seeders;

use App\Models\ReturnReason;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ReturnReasonSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultReasons = [
            ['code' => 'DAMAGED', 'name' => 'Damaged'],
            ['code' => 'WRONG_ITEM', 'name' => 'Wrong item'],
            ['code' => 'EXPIRED', 'name' => 'Expired'],
            ['code' => 'CUSTOMER_CHANGED_MIND', 'name' => 'Customer changed mind'],
            ['code' => 'QUALITY_ISSUE', 'name' => 'Quality issue'],
            ['code' => 'OTHER', 'name' => 'Other'],
        ];

        foreach ($defaultReasons as $reason) {
            ReturnReason::firstOrCreate(
                ['code' => $reason['code']],
                [
                    'id' => (string) Str::uuid(),
                    'user_id' => null,
                    'name' => $reason['name'],
                    'is_system' => true,
                    'is_active' => true,
                ]
            );
        }
    }
}
