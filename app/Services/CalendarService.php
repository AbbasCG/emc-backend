<?php

namespace App\Services;

use App\Models\CalendarEvent;

/**
 * Calendar event management and ICS generation.
 *
 * Supports:
 * - Creating CalendarEvent records from polymorphic sources.
 * - Generating RFC 5545-compliant ICS strings for download.
 * - Future Google / Outlook sync hooks (stubbed).
 */
class CalendarService
{
    /**
     * Create a calendar event from any Eloquent model (session, meeting, task, etc.).
     */
    public function createFromModel(
        \Illuminate\Database\Eloquent\Model $model,
        string $title,
        \DateTimeInterface $startAt,
        ?\DateTimeInterface $endAt = null,
        ?int $userId = null,
        array $extra = [],
    ): CalendarEvent {
        return CalendarEvent::create([
            'user_id'          => $userId,
            'eventable_type'   => get_class($model),
            'eventable_id'     => $model->getKey(),
            'title'            => $title,
            'description'      => $extra['description'] ?? null,
            'start_at'         => $startAt,
            'end_at'           => $endAt,
            'location'         => $extra['location'] ?? null,
            'meeting_url'      => $extra['meeting_url'] ?? null,
            'external_provider' => null,
            'sync_status'      => 'pending',
        ]);
    }

    /**
     * Generate an RFC 5545-compliant ICS string for a CalendarEvent.
     */
    public function toIcs(CalendarEvent $event): string
    {
        $uid     = 'emc-event-' . $event->id . '@emc.sa';
        $now     = $this->formatIcsDate(now());
        $start   = $this->formatIcsDate($event->start_at);
        $end     = $event->end_at
            ? $this->formatIcsDate($event->end_at)
            : $this->formatIcsDate($event->start_at->copy()->addHour());

        $summary     = $this->escapeIcs($event->title);
        $description = $event->description ? $this->escapeIcs($event->description) : '';
        $location    = $event->location ? $this->escapeIcs($event->location) : '';
        $url         = $event->meeting_url ?? '';

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//EMC Platform//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            "UID:{$uid}",
            "DTSTAMP:{$now}",
            "DTSTART:{$start}",
            "DTEND:{$end}",
            "SUMMARY:{$summary}",
        ];

        if ($description) {
            $lines[] = "DESCRIPTION:{$description}";
        }
        if ($location) {
            $lines[] = "LOCATION:{$location}";
        }
        if ($url) {
            $lines[] = "URL:{$url}";
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Stub: sync event to Google Calendar.
     * Activate by configuring GOOGLE_CALENDAR_* env vars.
     */
    public function syncToGoogle(CalendarEvent $event): void
    {
        // TODO: integrate google/apiclient when GOOGLE_CALENDAR_CREDENTIALS is set.
        $event->update(['sync_status' => 'pending', 'external_provider' => 'google']);
    }

    // -----------------------------------------------------------------------

    private function formatIcsDate(\DateTimeInterface $dt): string
    {
        return (new \DateTime('@' . $dt->getTimestamp()))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Ymd\THis\Z');
    }

    private function escapeIcs(string $text): string
    {
        return str_replace(["\n", "\r", ',', ';', '\\'], ['\\n', '', '\\,', '\\;', '\\\\'], $text);
    }
}
