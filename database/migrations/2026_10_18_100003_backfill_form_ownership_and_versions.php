<?php

use App\Services\Forms\FormSnapshotService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Forms Phase 1 — gives every existing form an owner and a version, without changing what anyone can do.
 *
 *  - owner_id := created_by, so the original creator is the owner. Nothing about who may open a form changes:
 *    the access rules treat "owner or, when owner_id is empty, creator" the same way.
 *  - A form that was published (status active, closed or archived) or that already has responses gets ONE version, built
 *    from its current questions, marked origin `legacy`, and every response it already has is linked to it. Responses
 *    keep every answer exactly as stored; only the new `form_version_id` reference is filled in.
 *  - published_version_id is set for forms that were published, so the public link keeps serving exactly the questions
 *    it served before, now from the frozen version. published_at is the form's creation time (the best fact available;
 *    the real publish time was never recorded) and published_by stays empty (unknown).
 *  - visibility / responses_visibility keep their column defaults (organization / form_viewers): the Phase 0 rules.
 *
 * Idempotent: a form that already has a version is not given another, and an owner already set is never replaced.
 * Nothing is deleted or rewritten except by filling the new, previously empty columns. Down() is deliberately a no-op:
 * the schema migrations that precede this one drop the columns and tables when rolled back, and the original data was
 * never touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('form_versions') || ! Schema::hasColumn('dynamic_forms', 'owner_id')) {
            return;
        }

        DB::table('dynamic_forms')->orderBy('id')->chunkById(100, function ($forms) {
            foreach ($forms as $form) {
                $updates = [];

                if ($form->owner_id === null && $form->created_by !== null) {
                    $updates['owner_id'] = $form->created_by;
                }

                $wasPublished = in_array($form->status, ['active', 'closed', 'archived'], true);
                $hasResponses = DB::table('form_submissions')->where('form_id', $form->id)->exists();
                $versionId = DB::table('form_versions')->where('form_id', $form->id)->orderBy('version_number')->value('id');

                if (! $versionId && ($wasPublished || $hasResponses)) {
                    $questions = DB::table('form_questions')
                        ->where('form_id', $form->id)->whereNull('deleted_at')
                        ->orderBy('sort_order')->orderBy('id')->get();
                    $snapshot = FormSnapshotService::build($form, $questions, FormSnapshotService::settingsRecord($form));

                    $versionId = DB::table('form_versions')->insertGetId([
                        'form_id'        => $form->id,
                        'version_number' => 1,
                        'schema'         => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'schema_hash'    => FormSnapshotService::hash($snapshot),
                        'question_count' => count($snapshot['questions']),
                        'origin'         => 'legacy',
                        'note'           => 'نسخة مُنشأة تلقائياً للنموذج المنشور قبل تفعيل الإصدارات',
                        'created_by'     => null,
                        'published_at'   => $form->created_at,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);
                }

                if ($versionId) {
                    if ($form->published_version_id === null && $wasPublished) {
                        $updates['published_version_id'] = $versionId;
                        $updates['published_at'] = $form->created_at;
                    }
                    DB::table('form_submissions')->where('form_id', $form->id)->whereNull('form_version_id')->update(['form_version_id' => $versionId]);
                }

                if ($updates !== []) {
                    DB::table('dynamic_forms')->where('id', $form->id)->update($updates);
                }
            }
        });
    }

    public function down(): void
    {
        // Intentionally empty: see the class comment.
    }
};
