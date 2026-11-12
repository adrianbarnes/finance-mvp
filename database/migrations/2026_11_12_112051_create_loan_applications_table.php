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
        Schema::create('loan_applications', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('call_session_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount_pesewas');
            $table->unsignedSmallInteger('term_months');
            $table->string('status')->default('pending');
            $table->bigInteger('next_repayment_pesewas')->nullable();
            $table->date('next_repayment_due_on')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_applications');
    }
};
