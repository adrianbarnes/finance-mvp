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
        Schema::create('flow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_flow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('flow_steps')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('type');
            $table->string('name');
            $table->string('keypad_digit', 4)->nullable();
            $table->json('config')->nullable();
            $table->boolean('is_otherwise')->default(false);
            $table->foreignId('go_to_step_id')->nullable()->constrained('flow_steps')->nullOnDelete();
            $table->timestamps();

            $table->index(['call_flow_id', 'parent_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flow_steps');
    }
};
