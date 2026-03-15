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

                if ($unexcusedCount >= 6) { // Trigger on 6 and every absence after (for testing/persistent warning)
                    $student = \App\Models\Student::with(['user', 'parents.user'])->find($record['student_id']);
                    if ($student) {
                        \Illuminate\Support\Facades\Log::info("Triggering attendance warning for {$student->user->name} (Count: {$unexcusedCount})");
                        $toEmails = [];
                        foreach ($student->parents as $p) {
                            if ($p->user) {
                                $toEmails[] = $p->user->email;
                            }
                        }

                        // If no parents found, fallback to the student directly to ensure delivery
                        if (empty($toEmails) && $student->user) {
                            $toEmails[] = $student->user->email;
                        }

                        $ccEmails = [];
                        if ($student->user && !in_array($student->user->email, $toEmails)) {
                            $ccEmails[] = $student->user->email;
                        }

                        // Admin office CC
                        $ccEmails[] = 'attendanceschool02@gmail.com';

                        // Homeroom teacher CC
                        $user = request()->user();
                        if ($user && $user->role === 'teacher') {
                            $ccEmails[] = $user->email;
                        } else {
                            // Extract any teacher assigned to this class as a fallback
                            $teacherAssignment = \App\Models\ClassSubjectTeacher::with('teacher.user')
                                ->where('class_id', $request->class_id)
                                ->first();

                            $teacherEmail = optional(optional(optional($teacherAssignment)->teacher)->user)->email;
                            if ($teacherEmail) {
                                $ccEmails[] = $teacherEmail;
                            }
                        }

                        $ccEmails = array_values(array_unique($ccEmails));

                        try {
                            if (!empty($toEmails)) {
                                \Illuminate\Support\Facades\Mail::to($toEmails)
                                    ->cc($ccEmails)
                                    ->send(new \App\Mail\AttendanceWarning($student, $unexcusedCount));
                            }
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
