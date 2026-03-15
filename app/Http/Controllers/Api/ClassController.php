<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Subject;
use App\Models\AcademicYear;
use Illuminate\Http\Request;

class ClassController extends Controller
{
    public function index()
    {
        $activeYearId = AcademicYear::where('is_active', true)->value('id');

        $query = SchoolClass::withCount('students');
        if ($activeYearId) {
            $query->where('academic_year_id', $activeYearId);
        }

        return response()->json($query->get());
    }

    public function show($id)
    {
        return response()->json(SchoolClass::with(['students.user', 'students.parents.user', 'subjects'])->findOrFail($id));
    }

    public function teacherClasses(Request $request)
    {
        $teacher = $request->user()->teacher;
        if (!$teacher) return response()->json([], 404);

        $activeYearId = AcademicYear::where('is_active', true)->value('id');

        $classes = SchoolClass::whereHas('subjects', function($q) use ($teacher) {
            $q->where('teacher_id', $teacher->id);
        })->with(['subjects' => function($q) use ($teacher) {
            $q->where('teacher_id', $teacher->id);
        }, 'schedules'])
        ->when($activeYearId, fn($q) => $q->where('academic_year_id', $activeYearId))
        ->withCount('students')
        ->get();

        return response()->json($classes);
    }
}
