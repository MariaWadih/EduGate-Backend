<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use Illuminate\Http\Request;
use App\Models\Grade;
use App\Models\Student;

class SubjectController extends Controller
{
    public function index()
    {
        return response()->json(Subject::all());
    }

    public function store(Request $request)
    {
        $request->validate(['name' => 'required|string|unique:subjects']);
        $subject = Subject::create($request->all());
        return response()->json($subject, 201);
    }
    // SubjectController.php

public function mySubjects(Request $request)
{
    $student = $request->user()->student;
    if (!$student) return response()->json(['message' => 'Unauthorized'], 403);

    $subjects = \App\Models\ClassSubjectTeacher::where('class_id', $student->class_id)
        ->with('subject')
        ->get()
        ->pluck('subject')
        ->unique('id')
        ->values();

    return response()->json($subjects);
}

public function childSubjects(Request $request, $studentId)
{
    $parent = $request->user()->parent;
    if (!$parent) return response()->json(['message' => 'Unauthorized'], 403);

    $isChild = $parent->students()->where('students.id', $studentId)->exists();
    if (!$isChild) return response()->json(['message' => 'Not your child'], 403);

    $student = \App\Models\Student::find($studentId);
    if (!$student) return response()->json(['message' => 'Student not found'], 404);

    $subjects = \App\Models\ClassSubjectTeacher::where('class_id', $student->class_id)
        ->with('subject')
        ->get()
        ->pluck('subject')
        ->unique('id')
        ->values();

    return response()->json($subjects);
}


}
