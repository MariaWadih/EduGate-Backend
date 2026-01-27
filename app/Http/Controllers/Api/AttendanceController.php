<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AttendanceController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'class_id' => 'required|exists:classes,id',
            'date' => 'required|date',
            'records' => 'required|array',
            'records.*.student_id' => 'required|exists:students,id',
            'records.*.status' => 'required|in:present,absent,late,excused'
        ]);

        foreach ($request->records as $record) {
            AttendanceRecord::updateOrCreate(
                [
                    'student_id' => $record['student_id'],
                    'class_id' => $request->class_id,
                    'date' => $request->date
                ],
                ['status' => $record['status']]
            );
        }

        return response()->json(['message' => 'Attendance marked successfully']);
    }

    public function myAttendance(Request $request)
    {
        $student = $request->user()->student;
        if (!$student) return response()->json([], 404);

        return response()->json(AttendanceRecord::where('student_id', $student->id)->orderBy('date', 'desc')->get());
    }
}
