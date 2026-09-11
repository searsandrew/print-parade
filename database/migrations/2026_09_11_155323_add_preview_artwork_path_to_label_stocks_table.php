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
        Schema::table('label_stocks', function (Blueprint $table) {
            $table->string('preview_artwork_path')->nullable()->after('sku');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('label_stocks', function (Blueprint $table) {
            $table->dropColumn('preview_artwork_path');
        });
    }
};
