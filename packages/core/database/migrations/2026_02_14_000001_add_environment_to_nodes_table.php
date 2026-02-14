<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('nodes', 'environment')) {
            Schema::table('nodes', function (Blueprint $table) {
                $table->string('environment')->default('development')->after('node_type');
                $table->index('environment');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('nodes', 'environment')) {
            Schema::table('nodes', function (Blueprint $table) {
                $table->dropIndex(['environment']);
                $table->dropColumn('environment');
            });
        }
    }
};
