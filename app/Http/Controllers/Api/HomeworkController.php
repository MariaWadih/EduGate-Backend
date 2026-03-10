<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class HomeworkController extends Controller
{
    public function index(Request $request)
    {
        $classId = $request->query('class_id');
        $homeworks = Homework::query();

        if ($classId) {
            $homeworks->where('class_id', $classId);
        }

        $subjectId = $request->query('subject_id');
        if ($subjectId) {
            $homeworks->where('subject_id', $subjectId);
        }

        $user = $request->user();
        if ($user->role === 'teacher' && $user->teacher) {
            $homeworks->where('teacher_id', $user->teacher->id);
        }

        return response()->json($homeworks->with(['subject', 'schoolClass'])->latest()->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'class_id' => 'required|exists:classes,id',
            'subject_id' => 'required|exists:subjects,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'due_date' => 'required|date',
            'file' => 'nullable|file|max:10240', // 10MB max
        ]);

        $teacher = $request->user()->teacher;
        if (!$teacher) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Verify teacher teaches this subject in this class
        $exists = DB::table('class_subject_teacher')
            ->where('class_id', $validated['class_id'])
            ->where('subject_id', $validated['subject_id'])
            ->where('teacher_id', $teacher->id)
            ->exists();

        if (!$exists) {
            return response()->json(['message' => 'You are not assigned to teach this subject in this class.'], 403);
        }

        $filePath = null;
        $fileName = null;

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $fileName = $file->getClientOriginalName();
            $filePath = $file->store('homeworks', 'public');
        }

        $homework = Homework::create([
            ...$validated,
            'teacher_id' => $teacher->id,
            'file_path' => $filePath,
            'file_name' => $fileName,
        ]);

        return response()->json($homework, 201);
    }

    public function show($id)
    {
        return response()->json(Homework::with(['subject', 'schoolClass', 'teacher'])->findOrFail($id));
    }

    public function getSubmissions($id)
    {
        $submissions = HomeworkSubmission::where('homework_id', $id)
            ->with(['student.user'])
            ->get();
        return response()->json($submissions);
    }
    
    // Create or update a grade for a submission
    public function gradeSubmission(Request $request)
    {
       $validated = $request->validate([
           'submission_id' => 'required|exists:homework_submissions,id',
           'score' => 'required|numeric|min:0|max:100',
       ]);

       $submission = HomeworkSubmission::findOrFail($validated['submission_id']);
       $submission->score = $validated['score'];
       $submission->status = 'graded';
       $submission->graded_at = now();
       $submission->save();

       return response()->json($submission);
    }

    public function submitHomework(Request $request)
    {
        $validated = $request->validate([
            'homework_id' => 'required|exists:homeworks,id',
            'content' => 'nullable|string',
            'file' => 'nullable|file|max:10240',
        ]);

        $student = $request->user()->student;
        if (!$student) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $submission = HomeworkSubmission::where('homework_id', $validated['homework_id'])
            ->where('student_id', $student->id)
            ->first();

        $data = [
            'content' => $validated['content'],
            'status' => 'submitted',
            'submitted_at' => now(),
        ];

        if ($request->hasFile('file')) {
            // Delete old file if exists
            if ($submission && $submission->file_path) {
                Storage::disk('public')->delete($submission->file_path);
            }
            
            $file = $request->file('file');
            $data['file_name'] = $file->getClientOriginalName();
            $data['file_path'] = $file->store('submissions', 'public');
        }

        if ($submission) {
            $submission->update($data);
        } else {
            $currentEnrollment = $student->currentEnrollment;
            
            $data['homework_id'] = $validated['homework_id'];
            $data['student_id'] = $student->id;
            $data['enrollment_id'] = $currentEnrollment?->id;
            $submission = HomeworkSubmission::create($data);
        }

        return response()->json($submission, 201);
    }

    public function myHomework(Request $request)
    {
        $student = $request->user()->student;
        if (!$student) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Get student's current active enrollment
        $currentEnrollment = $student->currentEnrollment;
        if (!$currentEnrollment) {
            return response()->json([]);
        }

        // Get homework for student's current class and academic year context
        $homeworks = Homework::where('class_id', $currentEnrollment->class_id)
            ->with(['subject', 'teacher.user'])
            ->with(['submissions' => function($query) use ($student, $currentEnrollment) {
                $query->where('student_id', $student->id)
                      ->where('enrollment_id', $currentEnrollment->id);
            }])
            ->latest()
            ->get();

        return response()->json($homeworks);
    }

    public function downloadFile(Request $request)
    {
        $path = $request->query('path');
        if (!$path || !Storage::disk('public')->exists($path)) {
            return response()->json(['message' => 'File not found'], 404);
        }

        return Storage::disk('public')->download($path);
    }

    public function destroy($id)
    {
        $homework = Homework::findOrFail($id);
        if ($homework->file_path) {
            Storage::disk('public')->delete($homework->file_path);
        }
        $homework->delete();
        return response()->json(null, 204);
    }
}
