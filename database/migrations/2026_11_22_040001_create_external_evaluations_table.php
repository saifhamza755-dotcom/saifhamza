<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('university_report_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('evaluator_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['assigned', 'submitted', 'returned', 'approved'])->default('assigned')->index();
            $table->decimal('total_score', 5, 2)->default(0);
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('external_evaluation_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_evaluation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('indicator_id')->constrained()->restrictOnDelete();
            $table->decimal('score', 5, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['external_evaluation_id', 'indicator_id'], 'external_eval_indicator_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_evaluation_responses');
        Schema::dropIfExists('external_evaluations');
    }
};
