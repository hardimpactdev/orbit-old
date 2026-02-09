<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            $table->index('is_active');
            $table->index('is_local');
            $table->index('is_default');
            $table->index('status');
        });

        Schema::table('ssh_keys', function (Blueprint $table) {
            $table->index('is_default');
        });
    }

    public function down(): void
    {
        Schema::table('environments', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
            $table->dropIndex(['is_local']);
            $table->dropIndex(['is_default']);
            $table->dropIndex(['status']);
        });

        Schema::table('ssh_keys', function (Blueprint $table) {
            $table->dropIndex(['is_default']);
        });
    }
};
