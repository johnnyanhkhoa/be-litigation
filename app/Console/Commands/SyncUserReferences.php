<?php

namespace App\Console\Commands;

use App\Services\UserReferenceService;
use Illuminate\Console\Command;

class SyncUserReferences extends Command
{
    protected $signature = 'sync:user-references {--all : Include inactive users}';
    protected $description = 'Sync user references from CC database';

    protected $userRefService;

    public function __construct(UserReferenceService $userRefService)
    {
        parent::__construct();
        $this->userRefService = $userRefService;
    }

    public function handle()
    {
        $this->info('Starting user references sync...');

        try {
            $includeInactive = $this->option('all');

            $count = $this->userRefService->syncAllUsers($includeInactive);

            $this->info("✅ Successfully synced {$count} users!");

        } catch (\Exception $e) {
            $this->error('❌ Failed to sync users: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
