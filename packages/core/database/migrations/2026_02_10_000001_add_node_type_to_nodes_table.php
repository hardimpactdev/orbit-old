<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = Schema::hasTable('nodes') ? 'nodes' : 'environments';

        if (! Schema::hasColumn($tableName, 'node_type')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('node_type')->default('local')->after('is_local');
                $table->index('node_type');
            });
        }
    }

    public function down(): void
    {
        $tableName = Schema::hasTable('nodes') ? 'nodes' : 'environments';

        if (Schema::hasColumn($tableName, 'node_type')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex(['node_type']);
                $table->dropColumn('node_type');
            });
        }
    }
};
