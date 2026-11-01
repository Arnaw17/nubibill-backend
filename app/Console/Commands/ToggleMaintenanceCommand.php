<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ToggleMaintenanceCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:maintenance 
                            {--status= : enable (maintenance on, app inactive) or disable (maintenance off, app active)}
                            {--title= : Maintenance window title}
                            {--message= : Maintenance message displayed to users}
                            {--image= : Image URL displayed on maintenance screen}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Enable or disable application maintenance mode in MySQL app_config';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $statusInput = strtolower(trim((string) $this->option('status')));

        $row = DB::table('app_config')->where('id', 'system')->first();
        $payload = $row ? json_decode($row->payload, true) : [];
        $fields = $payload['fields'] ?? [];

        if (empty($statusInput)) {
            $currentStatus = ($fields['isActive'] ?? true) ? 'ACTIVE (Maintenance OFF)' : 'INACTIVE (Maintenance ON)';
            $this->info("Current System Status: {$currentStatus}");
            $this->line('Title: '.($fields['maintenanceTitle'] ?? 'N/A'));
            $this->line('Message: '.($fields['maintenanceMessage'] ?? 'N/A'));
            $this->line('Image: '.($fields['maintenanceImageUrl'] ?? 'N/A'));
            $this->line('Use --status=enable to turn ON maintenance, or --status=disable to turn OFF maintenance.');

            return Command::SUCCESS;
        }

        $isActive = ($statusInput === 'disable' || $statusInput === 'off' || $statusInput === 'active');

        $now = now()->toIso8601String();
        $fields['isActive'] = $isActive;
        $fields['updatedAt'] = $now;

        if ($this->option('title')) {
            $fields['maintenanceTitle'] = (string) $this->option('title');
        } elseif (! isset($fields['maintenanceTitle'])) {
            $fields['maintenanceTitle'] = 'System Maintenance';
        }

        if ($this->option('message')) {
            $fields['maintenanceMessage'] = (string) $this->option('message');
        } elseif (! isset($fields['maintenanceMessage'])) {
            $fields['maintenanceMessage'] = 'The ERP system is currently under maintenance. Please check back shortly.';
        }

        if ($this->option('image')) {
            $fields['maintenanceImageUrl'] = (string) $this->option('image');
        }

        $payload['fields'] = $fields;
        $payload['_documentId'] = 'system';
        $payload['_documentPath'] = 'app_config/system';

        if ($row) {
            DB::table('app_config')->where('id', 'system')->update([
                'payload' => json_encode($payload),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('app_config')->insert([
                'id' => 'system',
                'document_path' => 'app_config/system',
                'is_deleted' => 0,
                'payload' => json_encode($payload),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $modeText = $isActive ? 'DISABLED (System is now ACTIVE)' : 'ENABLED (System is now under MAINTENANCE)';
        $this->info('--------------------------------------------------');
        $this->info(" SUCCESS: Maintenance mode is now {$modeText}");
        $this->line(' Title: '.($fields['maintenanceTitle'] ?? ''));
        $this->line(' Message: '.($fields['maintenanceMessage'] ?? ''));
        $this->info('--------------------------------------------------');

        return Command::SUCCESS;
    }
}
