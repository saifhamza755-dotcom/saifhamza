<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('self_assessments', function (Blueprint $table) {
            $table->text('report_notes')->nullable()->after('total_score');
        });
    }

    public function down(): void
    {
        Schema::table('self_assessments', function (Blueprint $table) {
            $table->dropColumn('report_notes');
        });
    }
};
