<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GradeController extends Controller
{
    public function index(Request $request)
    {
        $classId = $request->query('class_id');
        $subjectId = $request->query('subject_id');
        $term = $request->query('term');

        $query = Grade::query();

        if ($classId) {
             // Grades don't directly have class_id, but students do.
             $query->whereHas('student', function($q) use ($classId) {
                 $q->where('class_id', $classId);
             });
        }
        if ($subjectId) $query->where('subject_id', $subjectId);
        if ($term) $query->where('term', $term);

        return response()->json($query->with(['student.user'])->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'grades' => 'required|array',
            'grades.*.student_id' => 'required|exists:students,id',
            'grades.*.subject_id' => 'required|exists:subjects,id',
            'grades.*.score' => 'required|numeric|min:0',
            'grades.*.max_score' => 'required|numeric|min:1',
            'grades.*.term' => 'required|string',
            'grades.*.comments' => 'nullable|string',
        ]);

        $teacher = $request->user()->teacher;
        if (!$teacher && $request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $teacherId = $teacher ? $teacher->id : null;

        $savedGrades = [];
        
        DB::transaction(function () use ($validated, $teacherId, &$savedGrades) {
            foreach ($validated['grades'] as $gradeData) {
                $student = Student::find($gradeData['student_id']);
                $currentEnrollment = $student?->currentEnrollment;

                $grade = Grade::updateOrCreate(
                    [
                        'student_id' => $gradeData['student_id'],
                        'subject_id' => $gradeData['subject_id'],
                        'term' => $gradeData['term'],
                        'enrollment_id' => $currentEnrollment?->id, // Isolate by enrollment
                    ],
                    [
                        'teacher_id' => $teacherId,
                        'score' => $gradeData['score'],
                        'max_score' => $gradeData['max_score'],
                        'comments' => $gradeData['comments'] ?? null,
                        'date' => now(),
                    ]
                );
                $savedGrades[] = $grade;
            }
        });

        return response()->json(['message' => 'Grades saved successfully', 'data' => $savedGrades], 200);
    }

    public function myGrades(Request $request)
    {
        $student = $request->user()->student;
        if (!$student) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $currentEnrollment = $student->currentEnrollment;
        if (!$currentEnrollment) {
            return response()->json([]);
        }

        $grades = Grade::where('student_id', $student->id)
            ->where('enrollment_id', $currentEnrollment->id)
            ->with(['subject', 'teacher.user'])
            ->latest()
            ->get();

        return response()->json($grades);
    }

//get grades for parent view of their child
    public function childGrades(Request $request, $studentId)
{
    $parent = $request->user()->parent;
    if (!$parent) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }

    // Make sure this student is actually a child of this parent
    $isChild = $parent->students()->where('students.id', $studentId)->exists();
    if (!$isChild) {
        return response()->json(['message' => 'This student is not your child'], 403);
    }

    $student = Student::find($studentId);
    $currentEnrollment = $student->currentEnrollment;
    if (!$currentEnrollment) {
        return response()->json([]);
    }

    $grades = Grade::where('student_id', $studentId)
        ->where('enrollment_id', $currentEnrollment->id)
        ->with(['subject', 'teacher.user'])
        ->latest()
        ->get();

    return response()->json($grades);
}
}
