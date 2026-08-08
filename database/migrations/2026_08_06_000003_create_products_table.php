<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->decimal('price', 14, 2);
            $table->string('title');
            $table->string('brand')->default('');
            $table->text('description');
            $table->enum('condition', ['Like New', 'Good', 'Fair'])->default('Good');
            $table->json('attributes')->nullable();
            $table->json('images')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->enum('moderation_status', ['pending', 'approved', 'rejected'])->default('pending')->index();
            $table->string('rejection_reason')->default('');
            $table->timestamp('moderated_at')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('station_id')->nullable()->constrained('stations')->nullOnDelete();
            $table->enum('status', ['active', 'reserved', 'sold', 'archived'])->default('active')->index();
            $table->boolean('accepts_offers')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
