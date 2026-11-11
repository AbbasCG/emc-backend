<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A question the applicant did not answer must be stored as "no answer" (NULL), not as an invented answer.
 *
 * Since the five-level assessment form, `experience_level`, `availability` and `motivation` are optional, and the
 * application used to fill the gaps with text nobody wrote (a stock motivation sentence, "مرن", "غير محدد").
 * These three columns were NOT NULL, which forced that. `gender` is deliberately left alone: its approved
 * 'unspecified' value is part of the field's contract.
 *
 * Existing rows are not touched: no data is rewritten, deleted or re-labelled.
 */
return new class extends Migration
{
    /** @var array<string, int|null> column => length (null = text) */
    private const COLUMNS = [
        'experience_level' => 50,
        'availability' => 100,
        'motivation' => null,
    ];

    public function up(): void
    {
        if (! Schema::hasTable('volunteer_requests')) {
            return;
        }

        Schema::table('volunteer_requests', function (Blueprint $table) {
            foreach (self::COLUMNS as $column => $length) {
                if (Schema::hasColumn('volunteer_requests', $column)) {
                    ($length === null ? $table->text($column) : $table->string($column, $length))->nullable()->change();
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('volunteer_requests')) {
            return;
        }

        // Rows saved without an answer cannot go back into a NOT NULL column as they are: they become an empty
        // string (still "no answer"), never an invented value.
        foreach (array_keys(self::COLUMNS) as $column) {
            if (Schema::hasColumn('volunteer_requests', $column)) {
                DB::table('volunteer_requests')->whereNull($column)->update([$column => '']);
            }
        }

        Schema::table('volunteer_requests', function (Blueprint $table) {
            foreach (self::COLUMNS as $column => $length) {
                if (Schema::hasColumn('volunteer_requests', $column)) {
                    ($length === null ? $table->text($column) : $table->string($column, $length))->nullable(false)->change();
                }
            }
        });
    }
};
