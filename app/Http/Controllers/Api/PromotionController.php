<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\SchoolClass;
use App\Models\AcademicYear;
use App\Models\StudentPromotion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use App\Models\StudentEnrollment;
use App\Services\PromotionService;

class PromotionController extends Controller
{
    protected $promotionService;

    public function __construct(PromotionService $promotionService)
    {
        $this->promotionService = $promotionService;
    }
    /**
     * Get promotion candidates for a specific academic year and class
     */
    public function getCandidates(Request $request)
    {
        $request->validate([
            'from_academic_year' => 'required|string',
            'to_academic_year' => 'required|string',
            'from_class_id' => 'nullable|exists:classes,id'
        ]);

        $query = Student::with(['user', 'schoolClass', 'grades', 'examResults.exam'])
            ->where('status', 'active') // Only Active students can be promoted
            ->where('current_academic_year', $request->from_academic_year);

        if ($request->from_class_id) {
            $query->where('class_id', $request->from_class_id);
        }

        $students = $query->get()->map(function ($student) use ($request) {
            $failed_academic = false;
            $fail_reason = "";

            // Check regular grades (must be > 50%)
            foreach ($student->grades as $grade) {
                $max = $grade->max_score ?: 100;
                if (($grade->score / $max) < 0.5) {
                    $failed_academic = true;
                    $fail_reason = "Low grade in " . ($grade->subject->name ?? 'Subject');
                    break;
                }
            }

            // Check exam results (must be > 50%)
            if (!$failed_academic) {
                foreach ($student->examResults as $result) {
                    $max = $result->exam->max_score ?: 100;
                    if (($result->score / $max) < 0.5) {
                        $failed_academic = true;
                        $fail_reason = "Failed exam: " . ($result->exam->title ?? 'Exam');
                        break;
                    }
                }
            }

            $currentGradeName = $student->schoolClass->name; // e.g. "Grade 10"
            $status = $failed_academic ? 'retained' : 'promoted';
            
            // Detect Final Year (Grade 11) - case insensitive and flexible on spacing
            $isFinalYear = preg_match('/Grade\s*11\b/i', $currentGradeName);

            if ($status === 'promoted' && $isFinalYear) {
                $status = 'graduated';
            }

            // Automation: Suggest Target Class
            $targetClassId = null;
            if ($status === 'promoted') {
                // Find current grade number
                if (preg_match('/Grade\s*(\d+)/i', $currentGradeName, $matches)) {
                    $nextNum = intval($matches[1]) + 1;
                    $nextGradeName = "Grade " . $nextNum;
                    
                    // Look for the next grade in the target year with the same section
                    $targetClass = SchoolClass::where('name', 'LIKE', $nextGradeName . '%')
                        ->where('section', $student->schoolClass->section)
                        ->where('academic_year', $request->to_academic_year)
                        ->first();
                    $targetClassId = $targetClass?->id;
                }
            } elseif ($status === 'retained') {
                // Repeating: same grade name, new year
                $targetClass = SchoolClass::where('name', $currentGradeName)
                    ->where('section', $student->schoolClass->section)
                    ->where('academic_year', $request->to_academic_year)
                    ->first();
                $targetClassId = $targetClass?->id;
            }
            // If graduated, targetClassId remains null as they leave the system

            $student->automated_status = $status;
            $student->automated_target_class_id = $targetClassId;
            $student->fail_reason = $fail_reason;
            
            return $student;
        });

        return response()->json([
            'students' => $students,
            'total' => $students->count()
        ]);
    }

    /**
     * Promote students to the next academic year
     */
    public function promoteStudents(Request $request)
    {
        $request->validate([
            'from_academic_year' => 'required|string',
            'to_academic_year' => 'required|string',
            'promotions' => 'required|array',
            'promotions.*.student_id' => 'required|exists:students,id',
            'promotions.*.to_class_id' => 'nullable|exists:classes,id',
            'promotions.*.status' => 'required|in:promoted,retained,graduated,transferred',
            'promotions.*.remarks' => 'nullable|string'
        ]);

        $results = $this->promotionService->promoteStudents(
            $request->promotions,
            $request->from_academic_year,
            $request->to_academic_year
        );

        return response()->json([
            'message' => 'Promotion process completed',
            'results' => $results
        ]);
    }

    /**
     * Get promotion history for a student
     */
    public function getStudentHistory($studentId)
    {
        $history = StudentPromotion::where('student_id', $studentId)
            ->with(['fromClass', 'toClass'])
            ->orderBy('promotion_date', 'desc')
            ->get();

        return response()->json($history);
    }

    /**
     * Get promotion statistics for an academic year
     */
    public function getYearStatistics($academicYear)
    {
        $stats = StudentPromotion::where('from_academic_year', $academicYear)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status');

        return response()->json([
            'academic_year' => $academicYear,
            'promoted' => $stats['promoted'] ?? 0,
            'failed' => $stats['failed'] ?? 0,
            'repeated' => $stats['repeated'] ?? 0,
            'total' => $stats->sum()
        ]);
    }

    /**
     * Bulk promote entire class to next grade
     */
    public function bulkPromoteClass(Request $request)
    {
        $request->validate([
            'from_class_id' => 'required|exists:classes,id',
            'to_class_id' => 'required|exists:classes,id',
            'from_academic_year' => 'required|string',
            'to_academic_year' => 'required|string',
            'retained_student_ids' => 'nullable|array',
            'retained_student_ids.*' => 'exists:students,id'
        ]);

        $students = Student::where('class_id', $request->from_class_id)
            ->where('current_academic_year', $request->from_academic_year)
            ->get();

        $retainedIds = $request->retained_student_ids ?? [];
        $promotions = [];

        foreach ($students as $student) {
            $status = in_array($student->id, $retainedIds) ? 'retained' : 'promoted';
            
            $promotions[] = [
                'student_id' => $student->id,
                'to_class_id' => $status === 'retained' ? $request->from_class_id : $request->to_class_id,
                'status' => $status,
                'remarks' => $status === 'retained' ? 'Academic criteria not met' : 'Bulk promoted',
                'from_class_id' => $request->from_class_id
            ];
        }

        $results = $this->promotionService->promoteStudents($promotions, $request->from_academic_year, $request->to_academic_year);

        return response()->json([
            'message' => 'Bulk promotion completed',
            'results' => $results
        ]);
    }

    /**
     * Copy class structure from source year to target year.
     */
    public function initializeTargetClasses(Request $request)
    {
        $request->validate([
            'from_academic_year' => 'required|string',
            'to_academic_year'   => 'required|string'
        ]);

        // Resolve target AcademicYear record (create it if it doesn't exist yet)
        $targetYearRecord = AcademicYear::firstOrCreate(
            ['name' => $request->to_academic_year],
            [
                'start_date' => substr($request->to_academic_year, 0, 4) . '-09-01',
                'end_date'   => substr($request->to_academic_year, 5, 4) . '-06-30',
                'is_active'  => false,
                'status'     => 'upcoming',
            ]
        );

        $sourceClasses = SchoolClass::where('academic_year', $request->from_academic_year)->get();

        if ($sourceClasses->isEmpty()) {
            return response()->json(['message' => 'No source classes found to replicate.'], 404);
        }

        $createdCount = 0;
        foreach ($sourceClasses as $class) {
            $exists = SchoolClass::where('name', $class->name)
                ->where('section', $class->section)
                ->where('academic_year', $request->to_academic_year)
                ->exists();

            if (!$exists) {
                SchoolClass::create([
                    'name'             => $class->name,
                    'section'          => $class->section,
                    'academic_year'    => $request->to_academic_year,
                    'academic_year_id' => $targetYearRecord->id,
                ]);
                $createdCount++;
            }
        }

        return response()->json([
            'message'       => "Successfully initialized $createdCount classes for {$request->to_academic_year}.",
            'created_count' => $createdCount
        ]);
    }

    /**
     * Get classes for a specific academic year string (used by the promotion dropdown).
     */
    public function getClassesForYear(Request $request)
    {
        $request->validate(['academic_year' => 'required|string']);

        $classes = SchoolClass::where('academic_year', $request->academic_year)
            ->withCount('students')
            ->get();

        return response()->json($classes);
    }

    /**
     * Find or create a class for students repeating the year.
     */
    private function findRepeatClass($originalClass, $newAcademicYear)
    {
        $repeatClass = SchoolClass::where('name', $originalClass->name)
            ->where('section', $originalClass->section)
            ->where('academic_year', $newAcademicYear)
            ->first();

        if (!$repeatClass) {
            $yearRecord = AcademicYear::where('name', $newAcademicYear)->first();
            $repeatClass = SchoolClass::create([
                'name'             => $originalClass->name,
                'section'          => $originalClass->section,
                'academic_year'    => $newAcademicYear,
                'academic_year_id' => $yearRecord?->id,
            ]);
        }

        return $repeatClass->id;
    }
}
