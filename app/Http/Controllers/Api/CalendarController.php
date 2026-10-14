<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\Classroom;
use Illuminate\Http\Request;

class CalendarController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $classIds = $user->isTeacher()
            ? Classroom::where('teacher_id', $user->id)->pluck('id')
            : Classroom::whereHas('enrollments', fn ($q) => $q->where('student_id', $user->id)->where('status', 'approved'))->pluck('id');

        $events = CalendarEvent::where('user_id', $user->id)
            ->orWhereIn('classroom_id', $classIds)
            ->orderBy('starts_at')->get()
            ->map(fn ($e) => $this->format($e, $user->id));

        return response()->json($events);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $this->validated($request);

        if (! empty($data['classroom_id'])) {
            abort_unless(
                $user->isTeacher() && Classroom::where('id', $data['classroom_id'])->where('teacher_id', $user->id)->exists(),
                403, 'You can only add events to your own classes.'
            );
        }

        $event = CalendarEvent::create($data + ['user_id' => $user->id]);

        return response()->json($this->format($event, $user->id), 201);
    }

    public function update(Request $request, CalendarEvent $event)
    {
        abort_unless($event->user_id === $request->user()->id, 403, 'Not your event.');

        $event->update($this->validated($request, true));

        return response()->json($this->format($event->fresh(), $request->user()->id));
    }

    public function destroy(Request $request, CalendarEvent $event)
    {
        abort_unless($event->user_id === $request->user()->id, 403, 'Not your event.');

        $event->delete();

        return response()->json(['message' => 'Event deleted.']);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $request->merge([
            'starts_at' => $request->input('starts_at') ?? $request->input('start') ?? $request->input('date'),
            'ends_at' => $request->input('ends_at') ?? $request->input('end'),
            'classroom_id' => $request->input('classroom_id') ?? $request->input('class_id'),
        ]);

        $required = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'title' => "$required|string|max:255",
            'description' => 'nullable|string',
            'starts_at' => "$required|date",
            'ends_at' => 'nullable|date',
            'classroom_id' => 'nullable|integer|exists:classrooms,id',
        ]);
    }

    private function format(CalendarEvent $e, int $viewerId): array
    {
        return [
            'id' => $e->id,
            'title' => $e->title,
            'description' => $e->description,
            'starts_at' => $e->starts_at,
            'ends_at' => $e->ends_at,
            'class_id' => $e->classroom_id,
            'editable' => $e->user_id === $viewerId,
        ];
    }
}