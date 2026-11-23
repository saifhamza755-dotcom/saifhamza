<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('improvement_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_cycle_id')->constrained()->restrictOnDelete();
            $table->foreignId('university_id')->constrained()->restrictOnDelete();
            $table->foreignId('college_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->string('title');
            $table->enum('status', ['draft', 'active', 'completed'])->default('draft')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id']);
        });

        Schema::create('improvement_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('improvement_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('standard_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('indicator_id')->nullable()->constrained()->nullOnDelete();
            $table->text('gap_description');
            $table->text('action_description');
            $table->string('responsible_party');
            $table->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->date('starts_on');
            $table->date('due_on');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->enum('status', ['planned', 'in_progress', 'completed'])->default('planned');
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('improvement_actions');
        Schema::dropIfExists('improvement_plans');
    }
};
