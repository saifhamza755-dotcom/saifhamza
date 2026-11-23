<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('university_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_cycle_id')->constrained()->restrictOnDelete();
            $table->foreignId('university_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['draft', 'submitted', 'returned', 'approved'])->default('draft')->index();
            $table->decimal('overall_score', 5, 2)->default(0);
            $table->unsignedInteger('college_count')->default(0);
            $table->json('standards_snapshot');
            $table->text('executive_summary')->nullable();
            $table->text('recommendations')->nullable();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
            $table->unique(['evaluation_cycle_id', 'university_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('university_reports');
    }
};
