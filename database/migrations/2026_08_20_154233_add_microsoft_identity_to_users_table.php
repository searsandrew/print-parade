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
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('microsoft_tenant_id')->nullable()->after('email');
            $table->uuid('microsoft_object_id')->nullable()->after('microsoft_tenant_id');
            $table->unique(['microsoft_tenant_id', 'microsoft_object_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['microsoft_tenant_id', 'microsoft_object_id']);
            $table->dropColumn(['microsoft_tenant_id', 'microsoft_object_id']);
        });
    }
};
