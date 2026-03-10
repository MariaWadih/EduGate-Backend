<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSubmission;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExamController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Exam::query()->with(['subject', 'teacher.user', 'schoolClass']);

        if ($user->role === 'student') {
            $student = $user->student;
            $currentEnrollment = $student->currentEnrollment;

            if (!$currentEnrollment) {
                return response()->json([]);
            }
            
            // Show all exams belonging to the student's CURRENT class context
            $query->where('class_id', $currentEnrollment->class_id);
            
            // Include student's submission if exists for this specific enrollment
            $query->with(['submissions' => function($q) use ($student, $currentEnrollment) {
                $q->where('student_id', $student->id)
                  ->where('enrollment_id', $currentEnrollment->id);
            }]);
        } elseif ($user->role === 'teacher') {
            $query->where('teacher_id', $user->teacher->id);
            $query->with('questions');
        }

        return response()->json($query->latest()->get());
    }

    public function store(Request $request)
    {
        // Decode questions if sent as a string (common with FormData)
        if ($request->has('questions') && is_string($request->questions)) {
            $request->merge(['questions' => json_decode($request->questions, true)]);
        }

        $validated = $request->validate([
            'class_id' => 'required|exists:classes,id',
            'subject_id' => 'required|exists:subjects,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:file,mcq',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after:start_time',
            'duration_minutes' => 'nullable|numeric', // Changed from integer to numeric for flexibility
            'max_score' => 'required|numeric',
            'file' => 'nullable|file|max:10240',
            'questions' => 'nullable|array',
            'questions.*.question_text' => 'required_if:type,mcq|string',
            'questions.*.options' => 'required_if:type,mcq|array|min:2',
            'questions.*.correct_option' => 'required_if:type,mcq|string',
            'questions.*.points' => 'required_if:type,mcq|numeric',
        ]);

        $teacher = $request->user()->teacher;

        DB::beginTransaction();
        try {
            $filePath = null;
            $fileName = null;

            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $fileName = $file->getClientOriginalName();
                $filePath = $file->store('exams', 'public');
            }

            $exam = Exam::create([
                ...collect($validated)->except(['questions', 'file'])->toArray(),
                'teacher_id' => $teacher->id,
                'date' => date('Y-m-d', strtotime($validated['start_time'])),
                'file_path' => $filePath,
                'file_name' => $fileName,
            ]);

            if ($validated['type'] === 'mcq' && !empty($validated['questions'])) {
                foreach ($validated['questions'] as $qData) {
                    $exam->questions()->create($qData);
                }
            }

            DB::commit();
            return response()->json($exam->load('questions'), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create exam: ' . $e->getMessage()], 500);
        }
    }

    public function show($id, Request $request)
    {
        $user = $request->user();
        $exam = Exam::with(['subject', 'teacher.user', 'questions'])->findOrFail($id);

        if ($user->role === 'student') {
            $now = now();
            // Check if visible
            if ($exam->start_time > $now) {
                return response()->json(['message' => 'Exam has not started yet.'], 403);
            }
            // Include/Create submission
            $submission = $exam->submissions()->where('student_id', $user->student->id)->first();
            $exam->setRelation('my_submission', $submission);
        }

        return response()->json($exam);
    }

    public function submit(Request $request, $id)
    {
        $exam = Exam::findOrFail($id);
        $user = $request->user();
        
        if (!$user->student) {
            return response()->json(['message' => 'Student record not found.'], 403);
        }
        
        $student = $user->student;
        $now = now();

        if ($exam->end_time < $now) {
            return response()->json(['message' => 'Exam time has expired.'], 403);
        }

        // Decode mcq_answers if sent as a string (common with FormData)
        if ($request->has('mcq_answers') && is_string($request->mcq_answers)) {
            $request->merge(['mcq_answers' => json_decode($request->mcq_answers, true)]);
        }

        $validated = $request->validate([
            'mcq_answers' => 'nullable|array',
            'file' => 'nullable|file|max:10240',
        ]);

        try {
            $score = 0;
            $status = 'submitted';

            // Auto-grade MCQ
            if (!empty($validated['mcq_answers'])) {
                $exam = Exam::with('questions')->findOrFail($id);
                if ($exam->type === 'mcq' && $exam->questions) {
                    $status = 'graded';
                    foreach ($exam->questions as $question) {
                        $studentAnswer = $validated['mcq_answers'][$question->id] ?? null;
                        if ($studentAnswer !== null && (string)$studentAnswer === (string)$question->correct_option) {
                            $score += $question->points;
                        }
                    }
                }
            }

            $currentEnrollment = $student->currentEnrollment;

            $submission = ExamSubmission::updateOrCreate(
                [
                    'exam_id' => $id, 
                    'student_id' => $student->id,
                    'enrollment_id' => $currentEnrollment?->id // Link to enrollment
                ],
                [
                    'status' => $status,
                    'mcq_answers' => $validated['mcq_answers'] ?? null,
                    'submitted_at' => $now,
                    'score' => $score,
                ]
            );

            if ($request->hasFile('file')) {
                if ($submission->file_path) {
                    Storage::disk('public')->delete($submission->file_path);
                }
                $file = $request->file('file');
                $submission->file_name = $file->getClientOriginalName();
                $submission->file_path = $file->store('exam_submissions', 'public');
                $submission->save();
            }

            return response()->json($submission);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to submit: ' . $e->getMessage()], 500);
        }
    }

    public function getSubmissions($id)
    {
        $exam = Exam::findOrFail($id);
        
        // Get all students currently enrolled in this class
        // (We look for students whose current class_id matches the exam's class_id)
        $students = Student::where('class_id', $exam->class_id)
            ->with(['user', 'currentEnrollment'])
            ->get();
            
        // Get existing submissions for this exam, potentially filtered by enrollment if needed
        $submissions = ExamSubmission::where('exam_id', $id)->get()->keyBy('student_id');
        
        // Map students to include their submission data
        $results = $students->map(function($student) use ($submissions) {
            $submission = $submissions->get($student->id);
            return [
                'student' => $student,
                'id' => $submission ? $submission->id : null, // ID of the submission record
                'status' => $submission ? $submission->status : 'pending',
                'submitted_at' => $submission ? $submission->submitted_at : null,
                'score' => $submission ? $submission->score : null,
                'mcq_answers' => $submission ? $submission->mcq_answers : null,
                'file_path' => $submission ? $submission->file_path : null,
                'file_name' => $submission ? $submission->file_name : null,
            ];
        });

        return response()->json($results);
    }

    public function gradeSubmission(Request $request)
    {
        $validated = $request->validate([
            'submission_id' => 'required|exists:exam_submissions,id',
            'score' => 'required|numeric',
            'teacher_feedback' => 'nullable|string',
        ]);

        $submission = ExamSubmission::findOrFail($validated['submission_id']);
        $submission->update([
            'score' => $validated['score'],
            'teacher_feedback' => $validated['teacher_feedback'],
            'status' => 'graded',
            'graded_at' => now(),
        ]);

        return response()->json($submission);
    }
}
