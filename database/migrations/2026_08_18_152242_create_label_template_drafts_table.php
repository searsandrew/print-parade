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
        Schema::create('label_template_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('label_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('revision_code', 4);
            $table->unsignedSmallInteger('schema_version');
            $table->json('definition');
            $table->timestamps();

            $table->unique(['label_template_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('label_template_drafts');
    }
};
