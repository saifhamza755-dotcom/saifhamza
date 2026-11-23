<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('self_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_cycle_id')->constrained()->restrictOnDelete();
            $table->foreignId('university_id')->constrained()->restrictOnDelete();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['draft', 'submitted', 'returned', 'approved'])->default('draft')->index();
            $table->decimal('total_score', 5, 2)->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
            $table->unique(['evaluation_cycle_id', 'college_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('self_assessments');
    }
};
