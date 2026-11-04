<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateSuperUserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'superuser:create 
                            {email? : The email address for the superuser} 
                            {--password= : The password for the superuser} 
                            {--name= : The full name for the superuser} 
                            {--phone= : Phone number}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or promote a user to Superuser / Super Admin';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email') ?: $this->ask('Enter superuser email');
        $email = trim((string) $email);

        if (empty($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Please provide a valid email address.');

            return Command::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        $isNew = ! $user->exists;

        $name = $this->option('name') ?: ($isNew ? $this->ask('Enter full name', 'Super Administrator') : $user->name);
        $phone = $this->option('phone') ?: ($isNew ? $this->ask('Enter phone number', '9999999999') : ($user->phone ?: '9999999999'));

        $password = $this->option('password');
        if (empty($password)) {
            $password = $this->secret('Enter superuser password');
            if (empty($password) && $isNew) {
                $this->error('Password cannot be empty for a new user.');

                return Command::FAILURE;
            }
        }

        if ($isNew) {
            $user->id = (string) Str::uuid();
        }

        $user->name = $name ?: 'Super Administrator';
        $user->phone = $phone ?: '9999999999';
        if (! empty($password)) {
            $user->password = Hash::make($password);
        }
        $user->role = 'SUPER_ADMIN';
        $user->status = 'ACTIVE';
        $user->email_verified_at = $user->email_verified_at ?: now();
        $user->activated_at = $user->activated_at ?: now();
        $user->save();

        $this->info('--------------------------------------------------');
        $this->info($isNew ? ' SUCCESS: Superuser created successfully!' : ' SUCCESS: Existing user promoted to Superuser!');
        $this->info(" - Name:   {$user->name}");
        $this->info(" - Email:  {$user->email}");
        $this->info(" - Role:   {$user->role}");
        $this->info(" - Status: {$user->status}");
        $this->info('--------------------------------------------------');
        $this->info('You can now log in to NubiBill SuperUser with these credentials.');

        return Command::SUCCESS;
    }
}
