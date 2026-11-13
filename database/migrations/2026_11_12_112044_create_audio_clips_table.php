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
        Schema::create('audio_clips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_flow_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('group')->default('other');
            $table->timestamps();

            $table->unique(['call_flow_id', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audio_clips');
    }
};
