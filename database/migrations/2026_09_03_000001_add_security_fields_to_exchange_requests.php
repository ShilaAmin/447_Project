<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_requests', function (Blueprint $table) {
            $table->longText('encrypted_details')->nullable()->after('offered_item_id');
            $table->string('mac', 64)->nullable()->after('encrypted_details');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_requests', function (Blueprint $table) {
            $table->dropColumn(['encrypted_details', 'mac']);
        });
    }
};