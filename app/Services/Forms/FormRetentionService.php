<?php

namespace App\Services\Forms;

use App\Models\DynamicForm;
use App\Models\FormAnswer;
use App\Models\FormSubmission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Data retention for Smart Forms: a form's owner may decide that its responses are kept for a number of days and then deleted.
 *
 * Deleting is final, so it is careful: only forms with a retention period are touched, only responses older than it, the uploaded
 * files go with their answers, every purge is written in the form's history (how many, never what), and the command can be run with
 * --dry-run to see what would go. Answers, notes, history and corrections of a response are removed with it (database cascade).
 */
class FormRetentionService
{
    public const MIN_DAYS = 30;

    public const MAX_DAYS = 3650;

    public function __construct(private readonly FormAttachmentService $files) {}

    /** How many of the form's responses a given period would remove now. */
    public function expiredCount(DynamicForm $form, int $days, ?CarbonImmutable $now = null): int
    {
        return $this->expired($form, $days, $now)->count();
    }

    /**
     * Every form with a retention period: its expired responses, deleted (or only counted with $dryRun).
     *
     * @return list<array{form_id: int, title: string, responses: int}>
     */
    public function purge(bool $dryRun = false, ?CarbonImmutable $now = null): array
    {
        $report = [];
        DynamicForm::query()->whereNotNull('retention_days')->orderBy('id')->chunkById(100, function ($forms) use ($dryRun, $now, &$report) {
            foreach ($forms as $form) {
                $count = $dryRun ? $this->expiredCount($form, (int) $form->retention_days, $now) : $this->purgeForm($form, $now);
                if ($count > 0) {
                    $report[] = ['form_id' => $form->id, 'title' => (string) $form->title, 'responses' => $count];
                }
            }
        });

        return $report;
    }

    private function purgeForm(DynamicForm $form, ?CarbonImmutable $now): int
    {
        $count = 0;
        $this->expired($form, (int) $form->retention_days, $now)->select('id')->chunkById(200, function ($submissions) use (&$count) {
            $ids = $submissions->pluck('id')->all();
            $paths = FormAnswer::query()->whereIn('submission_id', $ids)->whereNotNull('file_path')->pluck('file_path')->all();

            DB::transaction(function () use ($ids) {
                // Children first, so the purge does not depend on the database enforcing its cascades.
                foreach (['form_answer_corrections', 'form_submission_notes', 'form_submission_events'] as $table) {
                    if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                        DB::table($table)->whereIn('submission_id', $ids)->delete();
                    }
                }
                FormAnswer::query()->whereIn('submission_id', $ids)->delete();
                FormSubmission::query()->whereIn('id', $ids)->delete();
            });

            // Files after the rows are gone: a failure here leaves an orphan file on the private disk, never an answer without its file.
            foreach ($paths as $path) {
                $disk = $this->files->diskFor($path);
                if ($disk !== null) {
                    Storage::disk($disk)->delete($path);
                }
            }
            $count += count($ids);
        });

        if ($count > 0) {
            $actor = User::query()->find($form->owner_id ?? $form->created_by);
            if ($actor !== null) {
                FormActivityService::record($form, $actor, 'responses_purged', ['count' => $count, 'note' => "مدة الاحتفاظ {$form->retention_days} يوماً"]);
            }
        }

        return $count;
    }

    private function expired(DynamicForm $form, int $days, ?CarbonImmutable $now)
    {
        $cutoff = ($now ?? CarbonImmutable::now())->subDays($days);

        return FormSubmission::query()->where('form_id', $form->id)->where('submitted_at', '<', $cutoff);
    }
}
