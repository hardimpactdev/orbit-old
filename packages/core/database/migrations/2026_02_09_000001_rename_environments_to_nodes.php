<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rename table if not already renamed
        if (Schema::hasTable('environments') && ! Schema::hasTable('nodes')) {
            Schema::rename('environments', 'nodes');
        }

        // Ensure we're working with the nodes table (may have already been renamed)
        $tableName = Schema::hasTable('nodes') ? 'nodes' : 'environments';

        // Drop is_local index if it exists (may have already been dropped)
        $indexExists = DB::select("SELECT name FROM sqlite_master WHERE type='index' AND name='environments_is_local_index'");
        if (! empty($indexExists)) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex('environments_is_local_index');
            });
        }

        // Drop is_local column if it exists
        if (Schema::hasColumn($tableName, 'is_local')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('is_local');
            });
        }

        // Update projects table foreign key
        if (Schema::hasColumn('projects', 'environment_id')) {
            if (DB::getDriverName() !== 'sqlite') {
                Schema::table('projects', function (Blueprint $table) {
                    $table->dropForeign(['environment_id']);
                });
            }

            Schema::table('projects', function (Blueprint $table) {
                $table->renameColumn('environment_id', 'node_id');
            });

            Schema::table('projects', function (Blueprint $table) {
                $table->foreign('node_id')->references('id')->on('nodes')->nullOnDelete();
            });
        }

        // Update workspaces table foreign key
        if (Schema::hasColumn('workspaces', 'environment_id')) {
            if (DB::getDriverName() !== 'sqlite') {
                Schema::table('workspaces', function (Blueprint $table) {
                    $table->dropForeign(['environment_id']);
                    $table->dropUnique(['environment_id', 'name']);
                });
            }

            Schema::table('workspaces', function (Blueprint $table) {
                $table->renameColumn('environment_id', 'node_id');
            });

            Schema::table('workspaces', function (Blueprint $table) {
                $table->foreign('node_id')->references('id')->on('nodes')->cascadeOnDelete();
                $table->unique(['node_id', 'name']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropForeign(['node_id']);
            $table->dropUnique(['node_id', 'name']);
            $table->renameColumn('node_id', 'environment_id');
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->foreign('environment_id')->references('id')->on('nodes')->cascadeOnDelete();
            $table->unique(['environment_id', 'name']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['node_id']);
            $table->renameColumn('node_id', 'environment_id');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreign('environment_id')->references('id')->on('nodes')->nullOnDelete();
        });

        Schema::table('nodes', function (Blueprint $table) {
            $table->boolean('is_local')->default(false)->after('port');
        });

        Schema::rename('nodes', 'environments');

        Schema::table('environments', function (Blueprint $table) {
            $table->index('is_local');
        });
    }
};
