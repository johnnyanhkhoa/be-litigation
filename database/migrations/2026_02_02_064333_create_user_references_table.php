<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_references', function (Blueprint $table) {
            // Primary key - same as authUserId in CC DB
            $table->string('id', 255)->primary();

            // Basic user info
            $table->string('email', 255)->unique();
            $table->string('username', 255)->nullable();
            $table->string('userFullName', 255)->nullable();
            $table->boolean('isActive')->default(true);
            $table->string('extensionNo')->nullable();
            $table->string('level')->nullable()->comment('team-leader, senior, mid-level, junior');

            // Sync metadata
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('email');
            $table->index('isActive');
            $table->index('level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_references');
    }
};
