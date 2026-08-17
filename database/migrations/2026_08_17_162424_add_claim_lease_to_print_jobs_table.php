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
            $table->string('claim_token_hash', 64)->nullable()->after('claimed_by_bridge');
            $table->timestamp('lease_expires_at')->nullable()->after('claimed_at');
            $table->timestamp('delivery_uncertain_at')->nullable()->after('lease_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropColumn(['claim_token_hash', 'lease_expires_at', 'delivery_uncertain_at']);
        });
    }
};
