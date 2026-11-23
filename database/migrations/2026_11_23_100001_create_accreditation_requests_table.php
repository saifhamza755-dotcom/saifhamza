<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accreditation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_evaluation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('university_id')->constrained()->restrictOnDelete();
            $table->foreignId('evaluation_cycle_id')->constrained()->restrictOnDelete();
            $table->text('rationale');
            $table->enum('status', ['submitted', 'issued', 'returned'])->default('submitted')->index();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at');
            $table->text('review_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accreditation_requests');
    }
};
