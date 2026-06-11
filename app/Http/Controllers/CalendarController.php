<?php

namespace App\Http\Controllers;

use App\Models\CalendarEvent;
use App\Services\CalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CalendarController extends Controller
{
    public function __construct(private readonly CalendarService $calendarService) {}

    /**
     * GET /api/calendar/events
     *
     * Returns calendar events for the authenticated user.
     * Supports ?from=YYYY-MM-DD&to=YYYY-MM-DD for date range filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $events = CalendarEvent::query()
            ->where('user_id', $user->id)
            ->when($request->from, fn ($q) => $q->where('start_at', '>=', $request->from))
            ->when($request->to, fn ($q) => $q->where('start_at', '<=', $request->to))
            ->orderBy('start_at')
            ->paginate(30);

        return response()->json([
            'success' => true,
            'data'    => $events->items(),
            'meta'    => [
                'current_page' => $events->currentPage(),
                'last_page'    => $events->lastPage(),
                'total'        => $events->total(),
            ],
        ]);
    }

    /**
     * GET /api/calendar/export.ics
     *
     * Download a single ICS file containing all calendar events for the authenticated user.
     */
    public function exportAll(Request $request): Response
    {
        $events = CalendarEvent::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('start_at')
            ->get();

        $ics = $this->calendarService->toBulkIcs($events);

        return response($ics, 200, [
            'Content-Type'        => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="emc-calendar.ics"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    /**
     * GET /api/calendar/events/{id}/ics
     *
     * Download ICS file for a single event.
     */
    public function downloadIcs(Request $request, CalendarEvent $calendarEvent): Response
    {
        // Only allow access to own events (or admin)
        if (! $request->user()->isAdmin() && $calendarEvent->user_id !== $request->user()->id) {
            abort(403);
        }

        $ics  = $this->calendarService->toIcs($calendarEvent);
        $slug = \Illuminate\Support\Str::slug($calendarEvent->title);

        return response($ics, 200, [
            'Content-Type'        => 'text/calendar; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$slug}.ics\"",
        ]);
    }
}
