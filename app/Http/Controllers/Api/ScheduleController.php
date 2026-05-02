<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Schedule;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    public function index(Request $request)
    {
        $query = Schedule::with(['subject', 'teacher', 'schoolClass']);

        if ($request->has('class_id')) {
            $query->where('class_id', $request->class_id);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
{
    $validated = $request->validate([
        'class_id'    => 'required|exists:classes,id',
        'subject_id'  => 'required|exists:subjects,id',
        'teacher_id'  => 'nullable|exists:teachers,id',
        'day_of_week' => 'required|string',
        'start_time'  => 'required',
        'end_time'    => 'required',
        'room'        => 'nullable|string',
    ]);

    // Auto-resolve teacher from class_subject_teacher if not provided
    if (empty($validated['teacher_id'])) {
        $assignment = \App\Models\ClassSubjectTeacher::where('class_id', $validated['class_id'])
            ->where('subject_id', $validated['subject_id'])
            ->first();

        if ($assignment) {
            $validated['teacher_id'] = $assignment->teacher_id;
        }
    }

    // Check 1: Room conflict
    if (!empty($validated['room'])) {
        $roomConflict = Schedule::where('room', $validated['room'])
            ->where('day_of_week', $validated['day_of_week'])
            ->where('start_time', $validated['start_time'])
            ->where('end_time', $validated['end_time'])
            ->exists();

        if ($roomConflict) {
            return response()->json([
                'message' => "Room '{$validated['room']}' is already booked on {$validated['day_of_week']} from {$validated['start_time']} to {$validated['end_time']}."
            ], 422);
        }
    }

    // Check 2: Teacher conflict
    if (!empty($validated['teacher_id'])) {
        $teacherConflict = Schedule::where('teacher_id', $validated['teacher_id'])
            ->where('day_of_week', $validated['day_of_week'])
            ->where('start_time', $validated['start_time'])
            ->where('end_time', $validated['end_time'])
            ->exists();

        if ($teacherConflict) {
            $teacher = \App\Models\Teacher::with('user')->find($validated['teacher_id']);
            $teacherName = $teacher?->user?->name ?? 'This teacher';
            return response()->json([
                'message' => "{$teacherName} already has a class on {$validated['day_of_week']} from {$validated['start_time']} to {$validated['end_time']}."
            ], 422);
        }
    }

    // Check 3: Class conflict
    $classConflict = Schedule::where('class_id', $validated['class_id'])
        ->where('day_of_week', $validated['day_of_week'])
        ->where('start_time', $validated['start_time'])
        ->where('end_time', $validated['end_time'])
        ->exists();

    if ($classConflict) {
        return response()->json([
            'message' => "This class section already has a subject scheduled on {$validated['day_of_week']} from {$validated['start_time']} to {$validated['end_time']}."
        ], 422);
    }

    $schedule = Schedule::create($validated);
    return response()->json($schedule->load(['subject', 'teacher', 'schoolClass']), 201);
}

    public function show(Schedule $schedule)
    {
        return response()->json($schedule->load(['subject', 'teacher', 'schoolClass']));
    }

    public function update(Request $request, Schedule $schedule)
    {
        $validated = $request->validate([
            'class_id' => 'sometimes|required|exists:classes,id',
            'subject_id' => 'sometimes|required|exists:subjects,id',
            'teacher_id' => 'nullable|exists:teachers,id',
            'day_of_week' => 'sometimes|required|string',
            'start_time' => 'sometimes|required',
            'end_time' => 'sometimes|required',
            'room' => 'nullable|string',
        ]);

        $schedule->update($validated);
        return response()->json($schedule->load(['subject', 'teacher', 'schoolClass']));
    }

    public function destroy(Schedule $schedule)
    {
        $schedule->delete();
        return response()->json(['message' => 'Schedule entry deleted successfully']);
    }

    public function mySchedule(Request $request)
{
    $student = $request->user()->student;
    if (!$student) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    $schedules = Schedule::with(['subject', 'teacher.user'])
        ->where('class_id', $student->class_id)
        ->get()
        ->groupBy('day_of_week');

    return response()->json($schedules);
}
}
