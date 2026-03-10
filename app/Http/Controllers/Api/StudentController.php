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
            'status' => 'sometimes|string|in:active,unenrolled,alumni,transferred,inactive'
        ]);

        return DB::transaction(function () use ($request, $student, $user) {
            $user->update([
                'name' => $request->name,
                'email' => $request->email,
            ]);

            if ($request->filled('password')) {
                $user->update(['password' => Hash::make($request->password)]);
            }

            $oldStatus = $student->status;
            $student->update([
                'class_id' => $request->class_id,
                'status' => $request->status ?? $student->status
            ]);

            // If status changed to non-active, trigger revocation
            if ($student->status !== 'active' && $oldStatus === 'active') {
                $this->revokeAccess($student);
            }

            return response()->json($student->load(['user', 'schoolClass']));
        });
    }

    private function revokeAccess($student)
    {
        $user = $student->user;
        if ($user) {
            $user->tokens()->delete();
        }

        // Check parents and revoke their tokens if they have no other active children
        foreach ($student->parents as $parent) {
            $hasActiveChildren = $parent->students()
                ->where('students.id', '!=', $student->id)
                ->where('status', 'active')
                ->exists();

            if (!$hasActiveChildren) {
                $parentUser = $parent->user;
                if ($parentUser) {
                    $parentUser->tokens()->delete();
                }
            }
        }
    }

    public function destroy($id)
    {
        $student = Student::findOrFail($id);
        $student->user()->delete(); 
        return response()->json(['message' => 'Student deleted successfully']);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|string|in:active,unenrolled,alumni,transferred,inactive'
        ]);

        $student = Student::findOrFail($id);
        $student->update(['status' => $request->status]);

        // Revoke all tokens if status is not active
        if ($request->status !== 'active') {
            $this->revokeAccess($student);
        }

        return response()->json([
            'message' => 'Student status updated successfully and access revoked if inactive',
            'student' => $student
        ]);
    }
}
