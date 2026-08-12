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
        Schema::create('label_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('label_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('revision_code', 4);
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->json('definition');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['label_template_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('label_template_versions');
    }
};
