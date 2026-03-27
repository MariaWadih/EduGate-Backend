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
public function index(Request $request)
{
    $query = Teacher::with(['user', 'assignments' => function($q) use ($request) {
        if ($request->has('academic_year_id')) {
            $q->where('academic_year_id', $request->academic_year_id);
        }
        $q->with(['schoolClass', 'subject']);
    }]);

    // Use the new pivot table instead of whereHas on assignments
    if ($request->has('academic_year_id')) {
        $query->whereHas('academicYears', function($q) use ($request) {
            $q->where('academic_year_id', $request->academic_year_id);
        });
    }

    return $query->get();
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

            $teacher = Teacher::create([
                'user_id' => $user->id,
                'status' => 'active',
                'joined_at' => now()
            ]);

            $activeYear = \App\Models\AcademicYear::active();
            if ($activeYear) {
                DB::table('teacher_academic_years')->insertOrIgnore([
                    'teacher_id'       => $teacher->id,
                    'academic_year_id' => $activeYear->id,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }

            if ($request->has('assignments')) {
                $this->validateAssignments($teacher, $request->assignments);
                
                foreach ($request->assignments as $assignment) {
                    $class = \App\Models\SchoolClass::findOrFail($assignment['class_id']);
                    \App\Models\ClassSubjectTeacher::create([
                        'teacher_id' => $teacher->id,
                        'class_id' => $assignment['class_id'],
                        'subject_id' => $assignment['subject_id'],
                        'academic_year_id' => $class->academic_year_id,
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
            'status' => 'sometimes|string|in:active,inactive,former'
        ]);

        return DB::transaction(function () use ($request, $teacher, $user) {
            $user->update([
                'name' => $request->name,
                'email' => $request->email,
            ]);

            if ($request->filled('password')) {
                $user->update(['password' => Hash::make($request->password)]);
            }

            $oldStatus = $teacher->status;
            $newStatus = $request->status ?? $teacher->status;

            // If status changed to non-active, trigger revocation and clear assignments
// NEW
if ($newStatus !== 'active' && $oldStatus === 'active') {
    $activeYear = \App\Models\AcademicYear::active();
    if ($activeYear) {
        \App\Models\ClassSubjectTeacher::where('teacher_id', $teacher->id)
            ->where('academic_year_id', $activeYear->id)
            ->delete();
    }
    $user->tokens()->delete();
    $teacher->update(['status' => $newStatus]);
} else {
    // Update basic info and sync assignments if active
    if ($request->has('assignments')) {
        $this->validateAssignments($teacher, $request->assignments);
        $activeYear = \App\Models\AcademicYear::active();
        \App\Models\ClassSubjectTeacher::where('teacher_id', $teacher->id)
            ->where('academic_year_id', $activeYear?->id)
            ->delete();
                    foreach ($request->assignments as $assignment) {
                        $class = \App\Models\SchoolClass::findOrFail($assignment['class_id']);
                        \App\Models\ClassSubjectTeacher::create([
                            'teacher_id' => $teacher->id,
                            'class_id' => $assignment['class_id'],
                            'subject_id' => $assignment['subject_id'],
                            'academic_year_id' => $class->academic_year_id,
                        ]);
                    }
                }
                $teacher->update(['status' => $newStatus]);
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

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|string|in:active,inactive,former'
        ]);

        $teacher = Teacher::findOrFail($id);
        
        // If setting to inactive/former, remove all current assignments
// NEW
if ($request->status !== 'active') {
    $activeYear = \App\Models\AcademicYear::active();
    if ($activeYear) {
        \App\Models\ClassSubjectTeacher::where('teacher_id', $teacher->id)
            ->where('academic_year_id', $activeYear->id)
            ->delete();
    }
    $teacher->user->tokens()->delete();
}
        
        $teacher->update(['status' => $request->status]);

        return response()->json([
            'message' => 'Teacher status updated successfully and access revoked if inactive',
            'teacher' => $teacher->load(['user', 'assignments.schoolClass', 'assignments.subject'])
        ]);
    }

    /**
     * Validate teacher assignments for business rules:
     * 1. Teacher must be active
     * 2. No schedule conflicts (same time slots)
     * 3. Assignments are year-specific
     */
    private function validateAssignments($teacher, $assignments)
    {
        // Rule 1: Teacher must be active to receive assignments
        if ($teacher->status === 'inactive') {
            throw new \Exception('Cannot assign courses to an inactive teacher. Please activate the teacher first.');
        }

        // Rule 2: Check for schedule conflicts
        $classIds = array_column($assignments, 'class_id');
        $classes = \App\Models\SchoolClass::whereIn('id', $classIds)
            ->with('schedules')
            ->get()
            ->keyBy('id');

        $timeSlots = [];
        $assignedCourses = [];
        foreach ($assignments as $assignment) {
            // Rule 2.5: Prevent duplicate assignments in the same request
            $courseKey = $assignment['class_id'] . '_' . $assignment['subject_id'];
            if (isset($assignedCourses[$courseKey])) {
                throw new \Exception('Duplicate assignment detected in your request for the same class and subject.');
            }
            $assignedCourses[$courseKey] = true;

            // Rule 3: Check if the course is already assigned to another teacher
            $existingAssignment = \App\Models\ClassSubjectTeacher::where('class_id', $assignment['class_id'])
                ->where('subject_id', $assignment['subject_id'])
                ->where('teacher_id', '!=', $teacher->id)
                ->with('teacher.user')
                ->first();

            if ($existingAssignment) {
                $className = \App\Models\SchoolClass::find($assignment['class_id'])->name;
                $subjectName = \App\Models\Subject::find($assignment['subject_id'])->name;
                $otherTeacherName = $existingAssignment->teacher->user->name;

                throw new \Exception(
                    "The course '{$subjectName}' for class '{$className}' is already assigned to teacher '{$otherTeacherName}'. " .
                    "A course can only have one primary teacher per class."
                );
            }

            $class = $classes->get($assignment['class_id']);
            
            if ($class && $class->schedules) {
                foreach ($class->schedules as $schedule) {
                    // Check if this subject is in the schedule
                    if ($schedule->subject_id == $assignment['subject_id']) {
                        $timeKey = $schedule->day_of_week . '_' . $schedule->start_time;
                        
                        if (isset($timeSlots[$timeKey])) {
                            throw new \Exception(
                                "Schedule conflict detected: Teacher cannot teach two courses at the same time (" .
                                $schedule->day_of_week . " at " . $schedule->start_time . ")"
                            );
                        }
                        
                        $timeSlots[$timeKey] = [
                            'class' => $class->name . ' ' . $class->section,
                            'subject_id' => $assignment['subject_id']
                        ];
                    }
                }
            }
        }

        return true;
    }

        /**
     * Get teachers not in the current active year.
     */
    public function past()
{
    $activeYear = \App\Models\AcademicYear::active();

    if (!$activeYear) {
        return response()->json([]);
    }

    $teachers = Teacher::with(['user', 'assignments'])
        ->where(function($q) use ($activeYear) {
            // Not linked to current year at all
            $q->whereNotIn('id', function($sub) use ($activeYear) {
                $sub->select('teacher_id')
                    ->from('teacher_academic_years')
                    ->where('academic_year_id', $activeYear->id);
            })
            // OR linked but inactive/former
            ->orWhere(function($sub) use ($activeYear) {
                $sub->whereIn('id', function($inner) use ($activeYear) {
                    $inner->select('teacher_id')
                        ->from('teacher_academic_years')
                        ->where('academic_year_id', $activeYear->id);
                })->whereIn('status', ['inactive', 'former']);
            });
        })
        ->get();

    return response()->json($teachers);
}
    /**
     * Reactivate a past teacher into the current active year.
     */
    public function reactivate($id)
    {
        $teacher = Teacher::findOrFail($id);
        $activeYear = \App\Models\AcademicYear::active();

        if (!$activeYear) {
            return response()->json(['message' => 'No active academic year found'], 400);
        }

        DB::transaction(function() use ($teacher, $activeYear) {
            // Add to current year
            DB::table('teacher_academic_years')->insertOrIgnore([
                'teacher_id'       => $teacher->id,
                'academic_year_id' => $activeYear->id,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            // Reactivate status
            $teacher->update(['status' => 'active']);
        });

        return response()->json([
            'message' => 'Teacher reactivated successfully',
            'teacher' => $teacher->load(['user', 'assignments'])
        ]);
    }
}
