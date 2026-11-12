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
        Schema::create('call_turns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_session_id')->constrained()->cascadeOnDelete();
            $table->string('speaker');
            $table->string('input_mode')->nullable();
            $table->text('text')->nullable();
            $table->string('intent')->nullable();
            $table->string('state_after')->nullable();
            $table->string('audio_path')->nullable();
            $table->unsignedInteger('recognition_ms')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_turns');
    }
};
