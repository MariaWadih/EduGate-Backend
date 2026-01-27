<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class TeacherController extends Controller
{
    public function index()
    {
        return Teacher::with(['user', 'assignments.schoolClass', 'assignments.subject'])->get();
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'assignments' => 'nullable|array',
            'assignments.*.class_id' => 'required|exists:classes,id',
            'assignments.*.subject_id' => 'required|exists:subjects,id',
        ]);

        return DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'teacher',
            ]);

            $teacher = Teacher::create(['user_id' => $user->id]);

            if ($request->has('assignments')) {
                foreach ($request->assignments as $assignment) {
                    \App\Models\ClassSubjectTeacher::create([
                        'teacher_id' => $teacher->id,
                        'class_id' => $assignment['class_id'],
                        'subject_id' => $assignment['subject_id'],
                    ]);
                }
            }

            return response()->json($teacher->load(['user', 'assignments.schoolClass', 'assignments.subject']), 201);
        });
    }

    public function update(Request $request, $id)
    {
        $teacher = Teacher::findOrFail($id);
        $user = $teacher->user;

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:8',
            'assignments' => 'nullable|array',
            'assignments.*.class_id' => 'required|exists:classes,id',
            'assignments.*.subject_id' => 'required|exists:subjects,id',
        ]);

        return DB::transaction(function () use ($request, $teacher, $user) {
            $user->update([
                'name' => $request->name,
                'email' => $request->email,
            ]);

            if ($request->filled('password')) {
                $user->update(['password' => Hash::make($request->password)]);
            }

            // Sync assignments: Simplest way is to delete and recreate for this demo
            \App\Models\ClassSubjectTeacher::where('teacher_id', $teacher->id)->delete();
            
            if ($request->has('assignments')) {
                foreach ($request->assignments as $assignment) {
                    \App\Models\ClassSubjectTeacher::create([
                        'teacher_id' => $teacher->id,
                        'class_id' => $assignment['class_id'],
                        'subject_id' => $assignment['subject_id'],
                    ]);
                }
            }

            return response()->json($teacher->load(['user', 'assignments.schoolClass', 'assignments.subject']));
        });
    }

    public function show($id)
    {
        return Teacher::with(['user', 'assignments.schoolClass', 'assignments.subject'])->findOrFail($id);
    }

    public function destroy($id)
    {
        $teacher = Teacher::findOrFail($id);
        $teacher->user()->delete(); 
        return response()->json(['message' => 'Teacher deleted successfully']);
    }
}
