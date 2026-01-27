<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Subject;
use Illuminate\Http\Request;

class ClassController extends Controller
{
    public function index()
    {
        return response()->json(SchoolClass::withCount('students')->get());
    }

    public function show($id)
    {
        return response()->json(SchoolClass::with(['students.user', 'subjects'])->findOrFail($id));
    }

    public function teacherClasses(Request $request)
    {
        $teacher = $request->user()->teacher;
        if (!$teacher) return response()->json([], 404);

        $classes = SchoolClass::whereHas('subjects', function($q) use ($teacher) {
            $q->where('teacher_id', $teacher->id);
        })->with(['subjects' => function($q) use ($teacher) {
            $q->where('teacher_id', $teacher->id);
        }, 'schedules'])->withCount('students')->get();

        return response()->json($classes);
    }
}
