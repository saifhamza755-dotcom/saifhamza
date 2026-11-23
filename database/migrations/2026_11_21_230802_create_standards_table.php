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
        Schema::create('standards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('standards_version_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->decimal('weight', 5, 2);
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['standards_version_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('standards');
    }
};
