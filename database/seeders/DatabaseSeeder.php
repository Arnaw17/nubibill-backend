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
        // Admin user for review and system management
        $admin = new User();
        $admin->id = (string) Str::uuid();
        $admin->name = 'System Administrator';
        $admin->email = 'admin@nubibilling.com';
        $admin->phone = '9999999999';
        $admin->password = Hash::make('AdminPass123!');
        $admin->role = 'ADMIN';
        $admin->status = 'ACTIVE';
        $admin->email_verified_at = now();
        $admin->activated_at = now();
        $admin->save();
    }
}
