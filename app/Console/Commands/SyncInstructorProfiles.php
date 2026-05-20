<?php

namespace App\Console\Commands;

use App\Models\Instructor;
use App\Models\User;
use Illuminate\Console\Command;

class SyncInstructorProfiles extends Command
{
    protected $signature   = 'instructors:sync';
    protected $description = 'Create missing instructor profile rows for every user with role instructor or teacher.';

    public function handle(): int
    {
        $users = User::whereIn('role', ['instructor', 'teacher'])
            ->get(['id', 'name', 'role']);

        $created = 0;
        $synced  = 0;

        foreach ($users as $user) {
            $profile = Instructor::firstOrCreate(
                ['user_id' => $user->id],
                ['name'    => $user->name]
            );

            if ($profile->wasRecentlyCreated) {
                $created++;
                $this->line("  <fg=green>created</> profile for {$user->name} (user #{$user->id})");
            } elseif ($profile->name !== $user->name) {
                $profile->update(['name' => $user->name]);
                $synced++;
                $this->line("  <fg=yellow>synced</>  name for {$user->name} (user #{$user->id})");
            }
        }

        $this->info("Done. {$created} profile(s) created, {$synced} name(s) synced.");

        return self::SUCCESS;
    }
}
