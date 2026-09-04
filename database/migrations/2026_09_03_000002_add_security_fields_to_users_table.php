<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('name')->change();
            $table->text('email')->change();
            $table->text('phone')->nullable()->change();
            $table->text('nid_no')->nullable()->change();
            $table->text('address')->nullable()->after('nid_no');
            $table->string('email_hash', 64)->nullable()->unique()->after('email');
            $table->string('mac', 64)->nullable()->after('address');
            $table->text('rsa_public_key')->nullable()->after('mac');
            $table->text('ecc_public_key')->nullable()->after('rsa_public_key');
            $table->text('google2fa_secret')->nullable()->after('ecc_public_key');
            $table->string('session_token', 64)->nullable()->after('google2fa_secret');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'address',
                'email_hash',
                'mac',
                'rsa_public_key',
                'ecc_public_key',
                'google2fa_secret',
                'session_token',
            ]);
            $table->string('name')->change();
            $table->string('email')->change();
            $table->string('phone')->nullable()->change();
            $table->string('nid_no')->nullable()->change();
        });
    }
};
