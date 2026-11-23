<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accreditation_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_evaluation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('university_id')->constrained()->restrictOnDelete();
            $table->foreignId('evaluation_cycle_id')->constrained()->restrictOnDelete();
            $table->string('decision_number', 60)->unique();
            $table->enum('decision_type', ['accredited', 'conditional', 'not_accredited', 'deferred'])->index();
            $table->enum('status', ['issued', 'revoked'])->default('issued')->index();
            $table->decimal('final_score', 5, 2);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->text('rationale');
            $table->text('conditions')->nullable();
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at');
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accreditation_decisions');
    }
};
