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
            'records.*.status' => 'required|in:present,absent,late,excused',
            'records.*.remarks' => 'nullable|string'
        ]);

        foreach ($request->records as $record) {
            $studentModel = \App\Models\Student::find($record['student_id']);
            $currentEnrollment = $studentModel?->currentEnrollment;

            AttendanceRecord::updateOrCreate(
                [
                    'student_id' => $record['student_id'],
                    'class_id' => $request->class_id,
                    'date' => $request->date,
                    'enrollment_id' => $currentEnrollment?->id, // Scope to enrollment
                ],
                [
                    'status' => $record['status'],
                    'remarks' => $record['remarks'] ?? null
                ]
            );

            // Check for unexcused absences warning (More than 6 in a year)
            if ($record['status'] === 'absent' && (empty($record['remarks']) || trim($record['remarks']) === '')) {
                $unexcusedCount = AttendanceRecord::where('student_id', $record['student_id'])
                    ->where('enrollment_id', $currentEnrollment?->id) // Use enrollment context
                    ->where('status', 'absent')
                    ->where(function ($query) {
                        $query->whereNull('remarks')->orWhere('remarks', '');
                    })
                    ->count();

                if ($unexcusedCount == 7) { // This is the 7th unexcused absence (more than 6)
                    $student = \App\Models\Student::with(['user', 'parents.user'])->find($record['student_id']);
                    if ($student) {
                        $emails = [$student->user->email];
                        foreach ($student->parents as $p) {
                            if ($p->user) {
                                $emails[] = $p->user->email;
                            }
                        }

                        try {
                            \Illuminate\Support\Facades\Mail::to($emails)->send(new \App\Mail\AttendanceWarning($student, $unexcusedCount));
                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::error("Could not send attendance warning: " . $e->getMessage());
                        }
                    }
                }
            }
        }

        return response()->json(['message' => 'Attendance marked successfully']);
    }

    public function myAttendance(Request $request)
    {
        $student = $request->user()->student;
        if (!$student) return response()->json([], 404);

        $currentEnrollment = $student->currentEnrollment;
        if (!$currentEnrollment) return response()->json([]);

        return response()->json(
            AttendanceRecord::where('student_id', $student->id)
                ->where('enrollment_id', $currentEnrollment->id)
                ->orderBy('date', 'desc')
                ->get()
        );
    }

    public function getByClassDate(Request $request)
    {
        $request->validate([
             'class_id' => 'required',
             'date' => 'required|date'
        ]);

        $records = AttendanceRecord::where('class_id', $request->class_id)
                    ->where('date', $request->date)
                    ->get();
        return response()->json($records);
    }
}
