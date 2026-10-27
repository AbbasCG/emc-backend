<?php

namespace App\Console\Commands;

use App\Services\Forms\Automation\FormAutomationService;
use Illuminate\Console\Command;

class RemindPendingFormResponsesCommand extends Command
{
    protected $signature = 'forms:remind-pending';

    protected $description = 'Remind reviewers, once a day, of form responses still open after the delay set on each form.';

    public function handle(FormAutomationService $automation): int
    {
        $this->info('Pending-response reminders sent: '.$automation->remindPending());

        return self::SUCCESS;
    }
}
