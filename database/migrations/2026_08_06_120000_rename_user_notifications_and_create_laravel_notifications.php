<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Free the `notifications` name for Laravel/Filament database notifications.
        if (Schema::hasTable('notifications') && ! Schema::hasTable('user_notifications')) {
            Schema::rename('notifications', 'user_notifications');
        }

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');

        if (Schema::hasTable('user_notifications') && ! Schema::hasTable('notifications')) {
            Schema::rename('user_notifications', 'notifications');
        }
    }
};
