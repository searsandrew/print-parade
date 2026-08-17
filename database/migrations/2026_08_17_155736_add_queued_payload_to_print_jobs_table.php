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
            $table->longText('output_payload')->nullable()->after('status');
            $table->string('output_checksum', 64)->nullable()->after('output_payload');
            $table->timestamp('queued_at')->nullable()->after('executed_by');
            $table->timestamp('claimed_at')->nullable()->after('queued_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropColumn(['output_payload', 'output_checksum', 'queued_at', 'claimed_at']);
        });
    }
};
