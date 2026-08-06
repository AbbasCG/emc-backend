<?php

namespace App\Console\Commands;

use App\Mail\SessionReminderMail;
use App\Models\CourseSession;
use App\Models\LmsSession;
use App\Models\NotificationLog;
use App\Models\Registration;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendSessionRemindersCommand extends Command
{
    protected $signature   = 'emc:send-session-reminders';
    protected $description = 'Send 24h and 1h session reminder emails for CourseSession and LmsSession';

    public function handle(): int
    {
        $now = Carbon::now();

        // Window definitions: [label, hoursAhead, lower_offset_minutes, upper_offset_minutes]
        $windows = [
            ['24h', 24, -30, 30],
            ['1h',   1, -15, 15],
        ];

        $sent = 0;

        foreach ($windows as [$windowKey, $hoursAhead, $lowerOffset, $upperOffset]) {
            $windowStart = $now->copy()->addHours($hoursAhead)->addMinutes($lowerOffset);
            $windowEnd   = $now->copy()->addHours($hoursAhead)->addMinutes($upperOffset);

            // ── CourseSession ─────────────────────────────────────────────────
            $courseSessions = CourseSession::whereBetween('start_at', [$windowStart, $windowEnd])
                ->whereNotNull('start_at')
                ->with(['course'])
                ->get();

            foreach ($courseSessions as $session) {
                if (!$session->course) {
                    continue;
                }

                $enrolledUserIds = Registration::where('course_id', $session->course_id)
                    ->whereIn('status', ['confirmed', 'active', 'approved'])
                    ->pluck('user_id')
                    ->unique()
                    ->all();

                $students = User::whereIn('id', $enrolledUserIds)->whereNotNull('email')->get();
                $startsAt = $session->start_at?->format('Y-m-d H:i');

                foreach ($students as $student) {
                    if ($this->alreadySent($student->email, $session->id, 'course_session', $windowKey)) {
                        continue;
                    }

                    EmailService::dispatch(
                        'session_reminder',
                        $student->email,
                        $student->name,
                        $student->id,
                        new SessionReminderMail(
                            $student->name,
                            $session->title,
                            $session->course->title ?? '',
                            $startsAt ?? '',
                            $session->meeting_link,
                            $hoursAhead
                        ),
                        "تذكير بجلستك: {$session->title}",
                        ['session_id' => $session->id, 'session_type' => 'course_session', 'window' => $windowKey]
                    );
                    $sent++;
                }
            }

            // ── LmsSession ────────────────────────────────────────────────────
            $lmsSessions = LmsSession::with(['course'])
                ->get()
                ->filter(function (LmsSession $s) use ($windowStart, $windowEnd) {
                    if (!$s->session_date || !$s->start_time) {
                        return false;
                    }
                    try {
                        $dt = Carbon::parse($s->session_date->format('Y-m-d') . ' ' . $s->start_time);
                        return $dt->between($windowStart, $windowEnd);
                    } catch (\Throwable) {
                        return false;
                    }
                });

            foreach ($lmsSessions as $session) {
                $courseId = $session->course_id ?? $session->workshop?->course_id;
                if (!$courseId) {
                    continue;
                }

                $enrolledUserIds = Registration::where('course_id', $courseId)
                    ->whereIn('status', ['confirmed', 'active', 'approved'])
                    ->pluck('user_id')
                    ->unique()
                    ->all();

                $students = User::whereIn('id', $enrolledUserIds)->whereNotNull('email')->get();
                $startsAt = $session->session_date->format('Y-m-d') . ' ' . $session->start_time;

                foreach ($students as $student) {
                    if ($this->alreadySent($student->email, $session->id, 'lms_session', $windowKey)) {
                        continue;
                    }

                    EmailService::dispatch(
                        'session_reminder',
                        $student->email,
                        $student->name,
                        $student->id,
                        new SessionReminderMail(
                            $student->name,
                            $session->title,
                            $session->course?->title ?? '',
                            $startsAt,
                            $session->meeting_url,
                            $hoursAhead
                        ),
                        "تذكير بجلستك: {$session->title}",
                        ['session_id' => $session->id, 'session_type' => 'lms_session', 'window' => $windowKey]
                    );
                    $sent++;
                }
            }
        }

        $this->info("Session reminder emails dispatched: {$sent}");
        return self::SUCCESS;
    }

    private function alreadySent(string $email, int $sessionId, string $sessionType, string $window): bool
    {
        return NotificationLog::where('channel', 'email')
            ->where('type', 'session_reminder')
            ->where('recipient', $email)
            ->where('created_at', '>=', now()->subMinutes(20))
            ->whereJsonContains('payload->session_id', $sessionId)
            ->whereJsonContains('payload->session_type', $sessionType)
            ->whereJsonContains('payload->window', $window)
            ->exists();
    }
}
