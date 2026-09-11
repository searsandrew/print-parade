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
        Schema::table('printers', function (Blueprint $table) {
            $table->decimal('horizontal_correction', 8, 3)->default(0)->after('dpi');
            $table->decimal('vertical_correction', 8, 3)->default(0)->after('horizontal_correction');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->dropColumn(['horizontal_correction', 'vertical_correction']);
        });
    }
};
