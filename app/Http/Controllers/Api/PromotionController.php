<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentPromotion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PromotionController extends Controller
{
    /**
     * GET /promotions/classes?year_id=X
     */
    public function classesByYear(Request $request)
    {
        $request->validate(['year_id' => 'required|exists:academic_years,id']);

        $classes = SchoolClass::where('academic_year_id', $request->year_id)
            ->orderBy('name')
            ->orderBy('section')
            ->withCount('students')
            ->get(['id', 'name', 'section', 'academic_year', 'academic_year_id']);

        return response()->json($classes);
    }

    /**
     * GET /promotions/preview?class_id=X&to_year_id=Y
     */
    public function preview(Request $request)
    {
        $request->validate([
            'class_id'   => 'required|exists:classes,id',
            'to_year_id' => 'required|exists:academic_years,id',
        ]);

        $sourceClass = SchoolClass::findOrFail($request->class_id);
        $toYear      = AcademicYear::findOrFail($request->to_year_id);

        // Parse grade number from "Grade 5", "Grade 11", etc.
        preg_match('/(\d+)/', $sourceClass->name, $m);
        $currentGrade = isset($m[1]) ? (int)$m[1] : null;
        $nextGrade    = $currentGrade ? $currentGrade + 1 : null;
        $isFinalGrade = $currentGrade === 12;

        // Find suggested next class: same section, next grade, in target year
        $suggestedClass = null;
        if ($nextGrade && !$isFinalGrade) {
            $suggestedClass = SchoolClass::where('academic_year_id', $toYear->id)
                ->where('name', 'Grade ' . $nextGrade)
                ->where('section', $sourceClass->section)
                ->first();
        }

        // All classes in target year for the dropdown
        $targetYearClasses = SchoolClass::where('academic_year_id', $toYear->id)
            ->orderBy('name')
            ->orderBy('section')
            ->get(['id', 'name', 'section']);

        // Students currently in this class
        $students = Student::where('class_id', $sourceClass->id)
            ->where('status', 'active')
            ->with('user')
            ->get()
            ->map(function ($student) use ($isFinalGrade, $suggestedClass, $toYear) {
                $alreadyPromoted = StudentEnrollment::where('student_id', $student->id)
                    ->where('academic_year_id', $toYear->id)
                    ->exists();

                return [
                    'id'                 => $student->id,
                    'name'               => $student->user->name,
                    'suggested_status'   => $isFinalGrade ? 'graduated' : 'promoted',
                    'suggested_class_id' => $suggestedClass?->id,
                    'already_promoted'   => $alreadyPromoted,
                ];
            });

        return response()->json([
            'source_class' => [
                'id'    => $sourceClass->id,
                'label' => "{$sourceClass->name} – Section {$sourceClass->section}",
                'grade' => $currentGrade,
            ],
            'target_year' => [
                'id'   => $toYear->id,
                'name' => $toYear->name,
            ],
            'is_final_grade'      => $isFinalGrade,
            'suggested_class'     => $suggestedClass
                ? ['id' => $suggestedClass->id, 'label' => "{$suggestedClass->name} – {$suggestedClass->section}"]
                : null,
            'target_year_classes' => $targetYearClasses,
            'students'            => $students,
        ]);
    }

    /**
     * POST /promotions/execute
     */
    public function execute(Request $request)
    {
        $request->validate([
            'from_class_id'          => 'required|exists:classes,id',
            'to_academic_year_id'    => 'required|exists:academic_years,id',
            'students'               => 'required|array|min:1',
            'students.*.id'          => 'required|exists:students,id',
            'students.*.status'      => 'required|in:promoted,retained,graduated',
            'students.*.to_class_id' => 'nullable|exists:classes,id',
        ]);

        $fromClass = SchoolClass::findOrFail($request->from_class_id);
        $toYear    = AcademicYear::findOrFail($request->to_academic_year_id);

        $results = ['success' => [], 'skipped' => [], 'errors' => []];

        DB::transaction(function () use ($request, $fromClass, $toYear, &$results) {
            foreach ($request->students as $entry) {
                $studentId = $entry['id'];
                $status    = $entry['status'];
                $toClassId = $entry['to_class_id'] ?? null;

                // Non-graduated students must have a target class
                if ($status !== 'graduated' && !$toClassId) {
                    $results['errors'][] = [
                        'id'     => $studentId,
                        'reason' => 'No target class selected',
                    ];
                    continue;
                }

                // Skip if already has any enrollment in target year
                $alreadyExists = StudentEnrollment::where('student_id', $studentId)
                    ->where('academic_year_id', $toYear->id)
                    ->exists();

                if ($alreadyExists) {
                    $results['skipped'][] = [
                        'id'     => $studentId,
                        'reason' => 'Already enrolled in target year',
                    ];
                    continue;
                }

                try {
                    $student = Student::findOrFail($studentId);

                    // Close ALL active enrollments for this student
                    // (don't filter by class_id — avoids mismatch issues)
                    StudentEnrollment::where('student_id', $studentId)
                        ->where('status', 'active')
                        ->update(['status' => 'promoted']);

                    if ($status === 'graduated') {
                        $student->update([
                            'status'   => 'alumni',
                            'class_id' => null,
                        ]);
                    } else {
                        // Create new active enrollment in target year
                        StudentEnrollment::create([
                            'student_id'       => $studentId,
                            'class_id'         => $toClassId,
                            'academic_year_id' => $toYear->id,
                            'academic_year'    => $toYear->name,
                            'status'           => 'active',
                            'enrollment_date'  => now(),
                            'notes'            => $status === 'retained'
                                ? 'Retained — repeated year'
                                : 'Promoted from ' . $fromClass->name,
                        ]);

                        $student->update([
                            'class_id'              => $toClassId,
                            'current_academic_year' => $toYear->name,
                        ]);
                    }

                    // Audit log
                    StudentPromotion::create([
                        'student_id'         => $studentId,
                        'from_class_id'      => $fromClass->id,
                        'to_class_id'        => $toClassId,
                        'from_academic_year' => $fromClass->academic_year,
                        'to_academic_year'   => $toYear->name,
                        'status'             => $status,
                        'promotion_date'     => now(),
                    ]);

                    $results['success'][] = $studentId;

                } catch (\Exception $e) {
                    $results['errors'][] = [
                        'id'     => $studentId,
                        'reason' => $e->getMessage(),
                    ];
                }
            }
        });

        $successCount = count($results['success']);
        $skippedCount = count($results['skipped']);
        $errorCount   = count($results['errors']);

        return response()->json([
            'message' => "{$successCount} promoted, {$skippedCount} skipped, {$errorCount} failed.",
            'results' => $results,
        ], $errorCount > 0 && $successCount === 0 ? 422 : 200);
    }
}