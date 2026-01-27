<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class StudentController extends Controller
{
    public function index()
    {
        return Student::with(['user', 'schoolClass', 'parents.user'])
            ->withAvg('grades', 'score')
            ->get();
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'class_id' => 'required|exists:classes,id',
        ]);

        return DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'student',
            ]);

            $student = Student::create([
                'user_id' => $user->id,
                'class_id' => $request->class_id,
            ]);

            return response()->json($student->load(['user', 'schoolClass']), 201);
        });
    }

    public function show($id)
    {
        return Student::with(['user', 'schoolClass', 'parents.user', 'grades.subject'])
            ->withAvg('grades', 'score')
            ->findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $student = Student::findOrFail($id);
        $user = $student->user;

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:8',
            'class_id' => 'required|exists:classes,id',
        ]);

        return DB::transaction(function () use ($request, $student, $user) {
            $user->update([
                'name' => $request->name,
                'email' => $request->email,
            ]);

            if ($request->filled('password')) {
                $user->update(['password' => Hash::make($request->password)]);
            }

            $student->update([
                'class_id' => $request->class_id,
            ]);

            return response()->json($student->load(['user', 'schoolClass']));
        });
    }

    public function destroy($id)
    {
        $student = Student::findOrFail($id);
        $student->user()->delete(); 
        return response()->json(['message' => 'Student deleted successfully']);
    }
}
