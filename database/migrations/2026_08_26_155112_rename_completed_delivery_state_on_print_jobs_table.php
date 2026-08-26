<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->renameColumn('completed_at', 'spooled_at');
        });

        DB::table('print_jobs')
            ->where('status', 'completed')
            ->update(['status' => 'spooled']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('print_jobs')
            ->where('status', 'spooled')
            ->update(['status' => 'completed']);

        Schema::table('print_jobs', function (Blueprint $table) {
            $table->renameColumn('spooled_at', 'completed_at');
        });
    }
};
