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
        Schema::create('step_prompt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_step_id')->constrained()->cascadeOnDelete();
            $table->string('slot')->default('prompt');
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('audio_clip_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('placeholder')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('step_prompt_items');
    }
};
