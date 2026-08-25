<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->json('definition_snapshot')->nullable()->after('input_values');
            $table->string('revision_code_snapshot', 4)->nullable()->after('definition_snapshot');
            $table->boolean('is_test')->default(false)->after('revision_code_snapshot')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropIndex(['is_test']);
            $table->dropColumn(['definition_snapshot', 'revision_code_snapshot', 'is_test']);
        });
    }
};
