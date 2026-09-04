<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            if (!Schema::hasColumn('items', 'mac')) {
                $table->string('mac', 64)->nullable()->after('photo');
            }
            if (!Schema::hasColumn('items', 'image_meta')) {
                $table->longText('image_meta')->nullable()->after('mac');
            }
            if (!Schema::hasColumn('items', 'image_meta_mac')) {
                $table->string('image_meta_mac', 64)->nullable()->after('image_meta');
            }
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $cols = array_filter(['mac', 'image_meta', 'image_meta_mac'], fn ($c) => Schema::hasColumn('items', $c));
            if ($cols) {
                $table->dropColumn($cols);
            }
        });
    }
};
