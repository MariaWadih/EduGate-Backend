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
            'class_id' => 'required|exists:classes,id',
            'subject_id' => 'required|exists:subjects,id',
            'teacher_id' => 'nullable|exists:teachers,id',
            'day_of_week' => 'required|string',
            'start_time' => 'required',
            'end_time' => 'required',
            'room' => 'nullable|string',
        ]);

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
}
