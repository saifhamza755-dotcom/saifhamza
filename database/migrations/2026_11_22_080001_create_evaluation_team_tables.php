<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluator_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('employer_university_id')->nullable()->constrained('universities')->nullOnDelete();
            $table->string('specialty')->nullable();
            $table->timestamps();
        });
        Schema::create('evaluator_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('university_id')->constrained()->cascadeOnDelete();
            $table->boolean('active')->default(true);
            $table->text('note');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['evaluator_id', 'university_id']);
        });
        Schema::create('evaluation_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_cycle_id')->constrained()->restrictOnDelete();
            $table->foreignId('university_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['evaluation_cycle_id', 'university_id']);
        });
        Schema::create('evaluation_team_user', function (Blueprint $table) {
            $table->foreignId('evaluation_team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['evaluation_team_id', 'user_id']);
        });
        Schema::create('evaluation_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_team_id')->constrained()->cascadeOnDelete();
            $table->date('visit_date');
            $table->time('visit_time');
            $table->text('agenda');
            $table->text('results')->nullable();
            $table->enum('status', ['scheduled', 'completed', 'cancelled'])->default('scheduled');
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_visits');
        Schema::dropIfExists('evaluation_team_user');
        Schema::dropIfExists('evaluation_teams');
        Schema::dropIfExists('evaluator_conflicts');
        Schema::dropIfExists('evaluator_profiles');
    }
};
