<?php

namespace App\Console\Commands;

use App\Models\PlatformNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ticket 8 canonical-architecture pass — one-time (safely rerunnable)
 * migration of any legacy rows in Laravel's built-in `notifications` table
 * (User-notifiable only) into the canonical `platform_notifications` store.
 *
 * The call-graph audit for this pass found zero live writers of the
 * built-in table (both former producers, `SendNotificationJob` and
 * `NotificationDispatcher::dispatchInApp`, were unreachable dead code), so
 * this command is expected to migrate 0 rows in this environment — it
 * exists as a safety net for any production data that predates this
 * finding, not because live data is known to exist.
 *
 * Never deletes the source rows. Idempotent: each migrated row is tagged
 * with `platform_notifications.legacy_source_id` = the source row's UUID
 * (unique-indexed), so a rerun skips already-migrated rows and never
 * duplicates them.
 */
class MigrateLegacyNotificationsCommand extends Command
{
    protected $signature = 'notifications:migrate-legacy
                            {--dry-run : Preview without writing any rows}';

    protected $description = 'Migrate legacy Laravel built-in `notifications` table rows (User-notifiable) into platform_notifications, once, without deleting the source.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('⚠  DRY-RUN — no rows will be written.');
            $this->newLine();
        }

        if (! \Illuminate\Support\Facades\Schema::hasTable('notifications')) {
            $this->info('No `notifications` table exists — nothing to migrate.');

            return self::SUCCESS;
        }

        $alreadyMigrated = PlatformNotification::whereNotNull('legacy_source_id')->pluck('legacy_source_id')->all();

        $rows = DB::table('notifications')
            ->where('notifiable_type', \App\Models\User::class)
            ->whereNotIn('id', $alreadyMigrated)
            ->orderBy('created_at')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No unmigrated legacy notification rows found.');

            return self::SUCCESS;
        }

        $migrated = 0;
        $skippedUnmappable = 0;

        foreach ($rows as $row) {
            $data = json_decode($row->data ?? '{}', true) ?: [];

            $userId = $row->notifiable_id;
            $title = $data['title'] ?? null;
            $message = $data['message'] ?? $data['content'] ?? null;

            if (! $userId || ! $title || ! $message) {
                $skippedUnmappable++;
                $this->warn("  → Skipped {$row->id}: missing " . implode('/', array_filter([
                    $userId ? null : 'notifiable_id',
                    $title ? null : 'title',
                    $message ? null : 'message',
                ])) . ' — cannot fabricate missing data.');

                continue;
            }

            $url = $data['url'] ?? null;
            $safeUrl = $this->safeExternalUrl($url);

            $this->line("  → Migrating {$row->id} → user #{$userId}: \"{$title}\"");

            if (! $dryRun) {
                // Model::create() silently drops created_at/updated_at (not
                // fillable) and re-stamps "now" — a raw insert is required
                // to genuinely preserve the legacy row's original timestamps.
                DB::table('platform_notifications')->insert([
                    'user_id'          => $userId,
                    'type'             => $row->type ?? ($data['type'] ?? 'legacy'),
                    'title'            => $title,
                    'message'          => $message,
                    'meta_url'         => $safeUrl,
                    'is_read'          => $row->read_at !== null,
                    'read_at'          => $row->read_at,
                    'legacy_source_id' => $row->id,
                    'created_at'       => $row->created_at,
                    'updated_at'       => $row->updated_at ?? $row->created_at,
                ]);
            }

            $migrated++;
        }

        $this->newLine();
        $this->info(($dryRun ? '[dry-run] Would migrate' : 'Migrated') . " {$migrated} notification(s).");
        if ($skippedUnmappable > 0) {
            $this->warn("Reported {$skippedUnmappable} unmappable row(s) — not migrated, source untouched.");
        }

        return self::SUCCESS;
    }

    private function safeExternalUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $parts = parse_url($url);
        if (! $parts || ! isset($parts['scheme']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }
}
