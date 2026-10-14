<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListCalendarRequest;
use App\Http\Requests\SaveCalendarEventRequest;
use App\Http\Resources\CalendarEventResource;
use App\Models\CalendarEvent;
use App\Services\CalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendarController extends Controller
{
    public function index(ListCalendarRequest $request, CalendarService $calendar): JsonResponse
    {
        $input = $request->validated();
        $month = $input['month'] ?? substr($calendar->today(), 0, 7);
        $first = CarbonImmutable::createFromFormat('!Y-m', $month);
        $last = $first->endOfMonth()->toDateString();
        $query = CalendarEvent::query()->where('couple_id', $request->user()->couple_id);
        if ($request->boolean('trash')) {
            $query->onlyTrashed();
        } else {
            $query->where('start_date', '<=', $last)->where('end_date', '>=', $first->toDateString());
        }
        if (! empty($input['event_type'])) {
            $query->where('event_type', $input['event_type']);
        }
        $page = $query->orderBy('start_date')->orderBy('id')->paginate(100);
        $items = CalendarEventResource::collection($page->items())->resolve($request);
        if ($page->currentPage() === 1 && ! $request->boolean('trash') && (empty($input['event_type']) || $input['event_type'] === 'anniversary')) {
            $anniversary = $calendar->anniversary($request->attributes->get('couple'), $first->year);
            if ($anniversary && substr($anniversary['start_date'], 0, 7) === $month) {
                $items[] = $anniversary;
            }
        }

        return response()->json(['success' => true, 'message' => 'Your shared calendar.', 'data' => ['items' => $items, 'month' => $month, 'today' => $calendar->today(), 'timezone' => config('tasks.timezone'), 'pagination' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]]);
    }

    public function upcoming(Request $request, CalendarService $calendar): JsonResponse
    {
        return response()->json(['success' => true, 'message' => 'What is next.', 'data' => $calendar->upcoming($request)]);
    }

    private function result(Request $request, CalendarEvent $event, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => (new CalendarEventResource($event))->resolve($request)], $status);
    }

    public function show(Request $request, int $event): JsonResponse
    {
        return $this->result($request, CalendarEvent::query()->where('couple_id', $request->user()->couple_id)->findOrFail($event), 'Your event.');
    }

    public function store(SaveCalendarEventRequest $request, CalendarService $calendar): JsonResponse
    {
        return $this->result($request, $calendar->save($request->user(), $request->validated()), 'Event created.', 201);
    }

    public function update(SaveCalendarEventRequest $request, int $event, CalendarService $calendar): JsonResponse
    {
        return $this->result($request, $calendar->save($request->user(), $request->validated(), $event), 'Event updated.');
    }

    public function destroy(Request $request, int $event, CalendarService $calendar): JsonResponse
    {
        $calendar->trash($request->user(), $event);

        return response()->json(['success' => true, 'message' => 'Event moved to trash.', 'data' => null]);
    }

    public function restore(Request $request, int $event, CalendarService $calendar): JsonResponse
    {
        return $this->result($request, $calendar->trash($request->user(), $event, true), 'Event restored.');
    }

    public function dismiss(Request $request, int $event, CalendarService $calendar): JsonResponse
    {
        return $this->result($request, $calendar->dismiss($request->user(), $event), 'Reminder dismissed.');
    }
}
