<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class ApproveUserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:approve {email : The email address of the user to activate}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Approve a user documents and activate their account';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = trim($this->argument('email'));

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("User with email [{$email}] not found.");

            return Command::FAILURE;
        }

        // Approve all documents
        $docsCount = $user->documents()->update([
            'status' => 'APPROVED',
            'reviewed_at' => now(),
        ]);

        // Activate user
        $user->status = 'ACTIVE';
        $user->activated_at = now();
        $user->save();

        $this->info('--------------------------------------------------');
        $this->info(' SUCCESS: User activated!');
        $this->info(" - Name:      {$user->name}");
        $this->info(" - Email:     {$user->email}");
        $this->info(" - Status:    {$user->status}");
        $this->info(" - Documents: {$docsCount} document(s) marked APPROVED");
        $this->info('--------------------------------------------------');

        return Command::SUCCESS;
    }
}
