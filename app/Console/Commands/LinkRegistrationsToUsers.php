<?php

namespace App\Console\Commands;

use App\Models\Registration;
use App\Models\User;
use Illuminate\Console\Command;

class LinkRegistrationsToUsers extends Command
{
    protected $signature = 'registrations:link-users';

    protected $description = 'Backfill registration.user_id from users.email where user_id is null';

    public function handle(): int
    {
        $updated = 0;

        Registration::query()
            ->whereNull('user_id')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$updated) {
                foreach ($chunk as $registration) {
                    $emailNorm = mb_strtolower(trim((string) $registration->email));
                    if ($emailNorm === '') {
                        continue;
                    }

                    $user = User::query()
                        ->whereRaw('LOWER(TRIM(email)) = ?', [$emailNorm])
                        ->first();

                    if ($user) {
                        $registration->forceFill(['user_id' => $user->id])->save();
                        $updated++;
                    }
                }
            });

        $this->info("Linked {$updated} registration(s) to users by email.");

        return self::SUCCESS;
    }
}
