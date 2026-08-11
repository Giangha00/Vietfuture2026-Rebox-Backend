<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'ai_meta')) {
                $table->json('ai_meta')->nullable()->after('moderation_notes');
            }
        });

        Schema::create('ai_training_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('image_urls');
            $table->string('category_slug')->nullable()->index();
            $table->json('ai_draft')->nullable();
            $table->json('user_final')->nullable();
            $table->string('admin_label')->nullable()->index();
            $table->text('rejection_reason')->nullable();
            $table->string('model_version')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_training_samples');

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'ai_meta')) {
                $table->dropColumn('ai_meta');
            }
        });
    }
};
