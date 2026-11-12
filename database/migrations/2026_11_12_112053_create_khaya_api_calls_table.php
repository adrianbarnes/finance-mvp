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
        Schema::create('khaya_api_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('endpoint');
            $table->string('language');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('khaya_api_calls');
    }
};
