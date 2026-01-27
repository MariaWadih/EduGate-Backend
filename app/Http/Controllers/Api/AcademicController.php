<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Teacher;
use Illuminate\Http\Request;

class AcademicController extends Controller
{
    public function storeGradeSubject(Request $request)
    {
        $request->validate([
            'grade_name' => 'required|string',
            'subject_name' => 'required|string',
            'subject_code' => 'nullable|string',
        ]);

        $subject = Subject::where('name', $request->subject_name)->first();
        if ($subject) {
            $subject->update(['code' => $request->subject_code]);
        } else {
            $subject = Subject::create([
                'name' => $request->subject_name,
                'code' => $request->subject_code
            ]);
        }

        $classes = SchoolClass::where('name', $request->grade_name)->get();

        if ($classes->isEmpty()) {
            return response()->json(['message' => 'Grade not found'], 404);
        }

        $teacher = Teacher::first();
        if (!$teacher) {
            return response()->json(['message' => 'No teachers found in system'], 400);
        }

        foreach ($classes as $class) {
            if (!$class->subjects()->where('subject_id', $subject->id)->exists()) {
                $class->subjects()->attach($subject->id, ['teacher_id' => $teacher->id]);
            }
        }

        return response()->json(['message' => 'Subject added successfully', 'subject' => $subject], 201);
    }


    public function storeGrade(Request $request)
    {
        $request->validate(['name' => 'required|string|unique:classes,name']);
        
        // Create first section of the grade
        $class = SchoolClass::create([
            'name' => $request->name,
            'section' => 'A'
        ]);

        return response()->json(['message' => 'Grade created successfully', 'grade' => $class], 201);
    }

    public function storeSection(Request $request)
    {
        $request->validate([
            'grade_name' => 'required|string',
            'section' => 'required|string'
        ]);

        $class = SchoolClass::create([
            'name' => $request->grade_name,
            'section' => $request->section
        ]);

        return response()->json(['message' => 'Section added successfully', 'section' => $class], 201);
    }

    public function destroyGrade(Request $request)
    {
        $request->validate(['name' => 'required|string']);
        SchoolClass::where('name', $request->name)->delete();
        return response()->json(['message' => 'Grade deleted successfully']);
    }

    public function destroySection($id)
    {
        SchoolClass::destroy($id);
        return response()->json(['message' => 'Section deleted successfully']);
    }

    public function destroyGradeSubject(Request $request)
    {
        $request->validate([
            'grade_name' => 'required|string',
            'subject_id' => 'required|integer'
        ]);

        $classes = SchoolClass::where('name', $request->grade_name)->get();
        foreach ($classes as $class) {
            $class->subjects()->detach($request->subject_id);
        }

        return response()->json(['message' => 'Subject removed from grade successfully']);
    }

    public function updateGrade(Request $request)
    {
        $request->validate([
            'old_name' => 'required|string',
            'new_name' => 'required|string'
        ]);

        SchoolClass::where('name', $request->old_name)->update(['name' => $request->new_name]);
        return response()->json(['message' => 'Grade updated successfully']);
    }

    public function updateSection(Request $request, $id)
    {
        $request->validate(['name' => 'required|string']);
        $class = SchoolClass::findOrFail($id);
        $class->update(['section' => $request->name]);
        return response()->json(['message' => 'Section updated successfully']);
    }

    public function updateSubject(Request $request, $id)
    {
        $request->validate(['name' => 'required|string']);
        $subject = Subject::findOrFail($id);
        $subject->update(['name' => $request->name]);
        return response()->json(['message' => 'Subject updated successfully']);
    }




    public function index()

    {
        // Group classes by grade name
        $classes = SchoolClass::with(['students.user', 'subjects'])->get();

        
        $hierarchy = $classes->groupBy('name')->map(function ($sections, $gradeName) {
            // Get unique subjects for this grade across all sections
            $subjects = $sections->flatMap->subjects->unique('id')->values();
            
            return [
                'name' => $gradeName,
                'sections' => $sections->map(function ($section) {
                    return [
                        'id' => $section->id,
                        'name' => $section->section,
                        'students_count' => $section->students->count(),
                        'subjects_count' => $section->subjects->count(),
                        'students' => $section->students->map(function ($student) {
                            return [
                                'id' => $student->id,
                                'name' => $student->user->name,
                                'email' => $student->user->email,
                            ];
                        }),
                        'subjects' => $section->subjects->map(function ($subject) {
                            return [
                                'id' => $subject->id,
                                'name' => $subject->name,
                            ];
                        }),
                        // In a real app, we'd have a schedule table. For now, we'll mock it if needed.
                        'schedule' => $this->getMockSchedule($section->section),
                    ];
                }),
                'subjects' => $subjects->map(function ($subject) {
                    return [
                        'id' => $subject->id,
                        'name' => $subject->name,
                        'code' => $subject->code ?? (strtoupper(substr($subject->name, 0, 2)) . rand(100, 999)),
                    ];
                }),
            ];
        })->values();

        return response()->json($hierarchy);
    }

    private function getMockSchedule($section)
    {
        return [
            ['subject' => 'Mathematics', 'time' => 'Mon 10:00-11:00, Wed 08:30-11:00'],
            ['subject' => 'English Language', 'time' => 'Tue 09:00-10:30, Thu 14:00-15:30'],
            ['subject' => 'Science', 'time' => 'Fri 11:00-12:00'],
        ];
    }
}
