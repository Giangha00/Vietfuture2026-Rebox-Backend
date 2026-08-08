<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['station_id']);
        });

        DB::statement('ALTER TABLE products MODIFY station_id BIGINT UNSIGNED NULL');

        Schema::table('products', function (Blueprint $table) {
            $table->foreign('station_id')
                ->references('id')
                ->on('stations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['station_id']);
        });

        DB::statement('ALTER TABLE products MODIFY station_id BIGINT UNSIGNED NOT NULL');

        Schema::table('products', function (Blueprint $table) {
            $table->foreign('station_id')
                ->references('id')
                ->on('stations')
                ->restrictOnDelete();
        });
    }
};
