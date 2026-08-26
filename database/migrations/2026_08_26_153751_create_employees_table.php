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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('employee_number')->nullable()->unique();
            $table->string('pin_hash')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        DB::table('users')
            ->whereNotNull('pin_hash')
            ->orderBy('id')
            ->each(function (object $user): void {
                DB::table('employees')->insert([
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'pin_hash' => $user->pin_hash,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
