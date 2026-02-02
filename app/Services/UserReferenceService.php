<?php

namespace App\Services;

use App\Models\UserReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class UserReferenceService
{
    /**
     * Sync a single user from CC DB to Litigation DB
     */
    public function syncUser(int|string $authUserId): ?UserReference
    {
        try {
            // Query user from CC DB by authUserId
            $user = DB::connection('pgsql_cc')
                ->table('users')
                ->where('authUserId', $authUserId)
                ->whereNull('deletedAt')
                ->first();

            if (!$user) {
                Log::warning('User not found in CC DB', ['authUserId' => $authUserId]);
                return null;
            }

            // Upsert to user_references (dùng authUserId làm unique key)
            $userRef = UserReference::updateOrCreate(
                ['authUserId' => $user->authUserId], // ← Tìm theo authUserId
                [
                    'id' => $user->id,  // ← id từ DB CC (số tự tăng)
                    'email' => $user->email,
                    'username' => $user->username,
                    'userFullName' => $user->userFullName,
                    'isActive' => $user->isActive ?? true,
                    'extensionNo' => $user->extensionNo,
                    'level' => $user->level,
                    'synced_at' => now(),
                ]
            );

            Log::info('User synced to Litigation DB', [
                'id' => $user->id,
                'authUserId' => $authUserId,
                'email' => $user->email
            ]);

            return $userRef;

        } catch (Exception $e) {
            Log::error('Failed to sync user', [
                'authUserId' => $authUserId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Ensure user exists in user_references table
     * If not, sync from CC DB
     */
    public function ensureUserExists(int|string $authUserId): UserReference
    {
        // Tìm theo authUserId
        $userRef = UserReference::where('authUserId', $authUserId)->first();

        if (!$userRef) {
            $userRef = $this->syncUser($authUserId);

            if (!$userRef) {
                throw new Exception("User with authUserId {$authUserId} not found in CC DB");
            }
        }

        return $userRef;
    }

    /**
     * Sync all active users from CC DB
     */
    public function syncAllUsers(bool $includeInactive = false): int
    {
        try {
            $query = DB::connection('pgsql_cc')
                ->table('users')
                ->whereNull('deletedAt');

            if (!$includeInactive) {
                $query->where('isActive', true);
            }

            $count = 0;

            $query->orderBy('id')->chunk(100, function ($users) use (&$count) {
                foreach ($users as $user) {
                    UserReference::updateOrCreate(
                        ['authUserId' => $user->authUserId], // ← Tìm theo authUserId
                        [
                            'id' => $user->id,  // ← id từ DB CC
                            'email' => $user->email,
                            'username' => $user->username,
                            'userFullName' => $user->userFullName,
                            'isActive' => $user->isActive ?? true,
                            'extensionNo' => $user->extensionNo,
                            'level' => $user->level,
                            'synced_at' => now(),
                        ]
                    );
                    $count++;
                }
            });

            Log::info('All users synced', ['count' => $count]);

            return $count;

        } catch (Exception $e) {
            Log::error('Failed to sync all users', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Get user info by authUserId (from local cache or sync)
     */
    public function getUserInfo(int|string $authUserId): ?UserReference
    {
        $userRef = UserReference::where('authUserId', $authUserId)->first();

        // If not found or outdated (older than 1 day), sync
        if (!$userRef || $userRef->synced_at < now()->subDay()) {
            $userRef = $this->syncUser($authUserId);
        }

        return $userRef;
    }
}
