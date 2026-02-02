<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_references', function (Blueprint $table) {
            $table->bigInteger('authUserId')->nullable()->after('id');
            $table->index('authUserId');
        });
    }

    public function down(): void
    {
        Schema::table('user_references', function (Blueprint $table) {
            $table->dropColumn('authUserId');
        });
    }
};
