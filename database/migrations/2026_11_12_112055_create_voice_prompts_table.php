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
        Schema::create('voice_prompts', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->string('language');
            $table->text('text');
            $table->string('audio_path')->nullable();
            $table->string('telephony_audio_path')->nullable();
            $table->timestamps();

            $table->unique(['key', 'language']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voice_prompts');
    }
};
