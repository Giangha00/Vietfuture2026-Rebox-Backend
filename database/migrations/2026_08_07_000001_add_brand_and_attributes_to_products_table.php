<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'brand')) {
                $table->string('brand')->default('')->after('title');
            }
            if (! Schema::hasColumn('products', 'attributes')) {
                $table->json('attributes')->nullable()->after('condition');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'brand')) {
                $table->dropColumn('brand');
            }
            if (Schema::hasColumn('products', 'attributes')) {
                $table->dropColumn('attributes');
            }
        });
    }
};
