<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->string('vpn_ip')->nullable()->after('host');
            $table->unsignedBigInteger('gateway_id')->nullable()->after('vpn_ip');
            $table->timestamp('vpn_registered_at')->nullable()->after('gateway_id');

            $table->index('vpn_ip');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropIndex(['vpn_ip']);
            $table->dropColumn(['vpn_ip', 'gateway_id', 'vpn_registered_at']);
        });
    }
};
