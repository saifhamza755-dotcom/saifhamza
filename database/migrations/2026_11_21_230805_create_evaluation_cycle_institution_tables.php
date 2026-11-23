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
        Schema::create('evaluation_cycle_university', function (Blueprint $table) {
            $table->foreignId('evaluation_cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('university_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->primary(['evaluation_cycle_id', 'university_id']);
        });

        Schema::create('evaluation_cycle_college', function (Blueprint $table) {
            $table->foreignId('evaluation_cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('college_id')->constrained()->restrictOnDelete();
            $table->foreignId('university_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->primary(['evaluation_cycle_id', 'college_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evaluation_cycle_college');
        Schema::dropIfExists('evaluation_cycle_university');
    }
};
