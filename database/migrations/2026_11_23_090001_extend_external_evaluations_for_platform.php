<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('external_evaluations', 'evaluation_team_id')) {
            Schema::table('external_evaluations', function (Blueprint $table) {
                $table->foreignId('evaluation_team_id')->nullable()->after('university_report_id')->constrained()->nullOnDelete();
                $table->text('strengths')->nullable()->after('review_notes');
                $table->text('weaknesses')->nullable()->after('strengths');
                $table->text('recommendations')->nullable()->after('weaknesses');
            });
        }

        if (! Schema::hasIndex('external_evaluation_responses', 'external_eval_indicator_lookup')) {
            DB::statement('CREATE INDEX external_eval_indicator_lookup ON external_evaluation_responses (indicator_id)');
        }
        Schema::table('external_evaluation_responses', function (Blueprint $table) {
            $table->foreignId('college_id')->nullable()->after('external_evaluation_id')->constrained()->restrictOnDelete();
            $table->unique(['external_evaluation_id', 'college_id', 'indicator_id'], 'external_eval_college_indicator_unique');
        });
        Schema::table('external_evaluation_responses', function (Blueprint $table) {
            $table->dropUnique('external_eval_indicator_unique');
        });
    }

    public function down(): void
    {
        Schema::table('external_evaluation_responses', function (Blueprint $table) {
            $table->dropUnique('external_eval_college_indicator_unique');
            $table->dropConstrainedForeignId('college_id');
            $table->unique(['external_evaluation_id', 'indicator_id'], 'external_eval_indicator_unique');
        });

        Schema::table('external_evaluations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('evaluation_team_id');
            $table->dropColumn(['strengths', 'weaknesses', 'recommendations']);
        });
    }
};
