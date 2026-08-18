<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_training_samples', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_training_samples', 'source')) {
                $table->string('source', 32)->default('user')->after('model_version')->index();
            }
            if (! Schema::hasColumn('ai_training_samples', 'exported_at')) {
                $table->timestamp('exported_at')->nullable()->after('source')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_training_samples', function (Blueprint $table) {
            if (Schema::hasColumn('ai_training_samples', 'exported_at')) {
                $table->dropColumn('exported_at');
            }
            if (Schema::hasColumn('ai_training_samples', 'source')) {
                $table->dropColumn('source');
            }
        });
    }
};
