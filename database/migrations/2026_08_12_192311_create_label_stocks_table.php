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
        Schema::create('label_stocks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('width', 8, 3)->unsigned();
            $table->decimal('height', 8, 3)->unsigned();
            $table->string('media_type')->default('gap');
            $table->text('description')->nullable();
            $table->string('sku')->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('label_stocks');
    }
};
