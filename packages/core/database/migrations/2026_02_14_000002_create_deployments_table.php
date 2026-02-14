<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();
            $table->string('project_slug');
            $table->string('project_name');
            $table->string('github_repo')->nullable();
            $table->string('domain')->nullable();
            $table->string('url')->nullable();
            $table->string('php_version')->nullable();
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            $table->string('cloudflare_record_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['node_id', 'project_slug']);
            $table->index('project_slug');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployments');
    }
};
