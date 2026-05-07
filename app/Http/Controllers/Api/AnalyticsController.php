<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Grade;
use App\Models\Homework;
use App\Models\HomeworkSubmission;
use App\Models\Insight;
use App\Models\Payment;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\UserParent;
use App\Models\Announcement;
use App\Models\SchoolClass;
use App\Models\StudentPromotion;
use App\Models\StudentEnrollment;
use App\Models\AcademicYear;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    //  ADMIN OVERVIEW
    // ─────────────────────────────────────────────────────────────────────────

    public function adminOverview(Request $request)
    {
        $yearId  = $request->query('academic_year_id');
        $classId = $request->query('class_id');   // replaces old "grade" string filter
        $segment = $request->query('segment');     // High Performers | At Risk | New Enrollees

        // ── Dynamic filter options (sent once so frontend can populate dropdowns) ──
        $filterOptions = [
            'classes'  => $this->getClassOptions($yearId),
            'segments' => ['All Students', 'High Performers', 'At Risk', 'New Enrollees'],
        ];

        $data = [
            'metrics' => [
                'total_students'   => $this->getFilteredStudentCount($yearId, $classId, $segment),
                'total_teachers'   => $this->getTeacherCount($yearId),
                'total_classes'    => $this->getFilteredClassCount($yearId, $classId),
                'total_subjects'   => $this->getSubjectCount($yearId),
                'attendance_rate'  => $this->getGlobalAttendanceRate($yearId, $classId),
                'proficiency_rate' => $this->getInstitutionalProficiencyRate($yearId, $classId),
                'growth_index'     => $this->calculateGrowthIndex($yearId),
                'retention_rate'   => $this->calculateRetentionRate($yearId),
            ],
            'rankings' => [
                'top_classes'       => $this->getTopPerformingClasses($yearId, $classId),
                'best_students'     => $this->getBestStudents($yearId, $classId, $segment),
                'subject_highlights'=> $this->getSubjectHighlights($yearId, $classId),
            ],
            'charts' => [
                'performance_trend'   => $this->getPerformanceTrend($yearId, $classId),
                'students_by_class'   => $this->getStudentsByClassCount($yearId, $classId),
                'registration_trend'  => $this->getRegistrationTrend(),
                'subject_performance' => $this->getSubjectPerformance($yearId, $classId),
                'grade_distribution'  => $this->getGradeDistribution($yearId, $classId),
                'attendance_trend'    => $this->getWeeklyAttendanceTrend($yearId, $classId),
            ],
            'operations' => [
                'teacher_student_ratio' => $this->getTeacherStudentRatio($yearId),
                'upcoming_year'         => $this->getUpcomingAcademicYear(),
                'active_year_name'      => AcademicYear::where('id', $yearId)->value('name')
                                          ?? AcademicYear::active()?->name
                                          ?? 'N/A',
            ],
            'insights'       => Insight::latest()->take(5)->get(),
            'announcements'  => Announcement::latest()->take(3)->get(),
            'feedback'       => $this->getRealFeedback(),
            'filter_options' => $filterOptions,
            'computed_at'    => now(),
            'filters_applied'=> compact('yearId', 'classId', 'segment'),
        ];

        return response()->json($data);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  FILTER OPTIONS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return [{id, label}] list of classes for the given year (or all classes).
     * Frontend uses this to populate the class filter dropdown dynamically.
     */
    private function getClassOptions($yearId): array
    {
        $query = SchoolClass::orderBy('name')->orderBy('section');
        if ($yearId) {
            $query->where('academic_year_id', $yearId);
        }
        return $query->get()
            ->map(fn($c) => [
                'id'    => $c->id,
                'label' => trim($c->name . ' ' . $c->section),
            ])
            ->values()
            ->toArray();
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  METRIC HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function getTeacherCount($yearId): int
    {
        if ($yearId) {
            return Teacher::whereHas('assignments', fn($q) =>
                $q->where('academic_year_id', $yearId)
            )->count();
        }
        return Teacher::count();
    }

    private function getSubjectCount($yearId): int
    {
        if ($yearId) {
            return \App\Models\ClassSubjectTeacher::where('academic_year_id', $yearId)
                ->distinct('subject_id')
                ->count('subject_id');
        }
        return \App\Models\Subject::count();
    }

protected function calculateGrowthIndex($yearId): ?float
{
    $current = $this->getInstitutionalProficiencyRate($yearId, null);
 
    $previousYear = $yearId
        ? \App\Models\AcademicYear::where('id', '<', $yearId)->orderByDesc('id')->first()
        : null;
 
    // No previous year to compare against — growth is undefined, not 0
    if (!$previousYear) return null;
 
    $previous = $this->getInstitutionalProficiencyRate($previousYear->id, null);
 
    return round($current - $previous, 1);
}

protected function calculateRetentionRate($yearId): ?float
{
    if (!$yearId) {
        // No year context: ratio of students who have ANY enrollment vs total
        $total  = \App\Models\Student::count();
        $active = \App\Models\StudentEnrollment::distinct('student_id')->count('student_id');
        if ($total === 0) return null;
        return round(($active / $total) * 100, 1);
    }
 
    $previousYear = \App\Models\AcademicYear::where('id', '<', $yearId)
        ->orderByDesc('id')
        ->first();
 
    // Only one academic year exists — cannot calculate retention
    if (!$previousYear) return null;
 
    $enrolledLastYear = \App\Models\StudentEnrollment::where('academic_year_id', $previousYear->id)
        ->distinct('student_id')
        ->count('student_id');
 
    if ($enrolledLastYear === 0) return null;
 
    $returnedThisYear = \App\Models\StudentEnrollment::where('academic_year_id', $yearId)
        ->whereIn(
            'student_id',
            \App\Models\StudentEnrollment::where('academic_year_id', $previousYear->id)
                ->pluck('student_id')
        )
        ->distinct('student_id')
        ->count('student_id');
 
    return round(($returnedThisYear / $enrolledLastYear) * 100, 1);
}

    private function getTeacherStudentRatio($yearId): string
    {
        $students = $this->getFilteredStudentCount($yearId, null, null);
        $teachers = $this->getTeacherCount($yearId);
        if ($teachers === 0) return 'N/A';
        return '1:' . round($students / $teachers);
    }

    private function getUpcomingAcademicYear(): ?string
    {
        $upcoming = AcademicYear::where('is_active', false)
            ->where('start_date', '>=', now())
            ->orderBy('start_date')
            ->first();
        return $upcoming?->name;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  STUDENT COUNT / FILTERING
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * NOTE: The original migration's `attendance_records` and `grades` tables
     * link directly to student_id + class_id, not enrollment_id. However,
     * student_enrollments were added later and grades/attendance were patched
     * to include enrollment_id. We use enrollment-based queries where possible
     * and fall back to student-based queries where not.
     */
    protected function getFilteredStudentCount($yearId, $classId = null, $segment = null): int
    {
        if ($yearId) {
            $query = StudentEnrollment::where('academic_year_id', $yearId);
            if ($classId) {
                $query->where('class_id', $classId);
            }
            $this->applySegmentToEnrollmentQuery($query, $segment, $yearId);
            return $query->distinct('student_id')->count('student_id');
        }

        // No year context — query students table directly
        $query = Student::query();
        if ($classId) {
            $query->where('class_id', $classId);
        }
        $this->applySegmentToStudentQuery($query, $segment);
        return $query->count();
    }

    private function applySegmentToEnrollmentQuery($query, $segment, $yearId): void
    {
        match ($segment) {
            'High Performers' => $query->whereHas('grades', fn($q) => $q->havingRaw('AVG(score) >= 85')),
            'At Risk'         => $query->whereHas('grades', fn($q) => $q->havingRaw('AVG(score) < 60')),
            'New Enrollees'   => $query->whereDoesntHave('student.enrollments', fn($q) =>
                                    $q->where('academic_year_id', '<', $yearId)
                                 ),
            default           => null,
        };
    }

    private function applySegmentToStudentQuery($query, $segment): void
    {
        match ($segment) {
            'High Performers' => $query->whereHas('grades', fn($q) => $q->havingRaw('AVG(score) >= 85')),
            'At Risk'         => $query->whereHas('grades', fn($q) => $q->havingRaw('AVG(score) < 60')),
            default           => null,
        };
    }

    protected function getFilteredClassCount($yearId, $classId = null): int
    {
        $query = SchoolClass::query();
        if ($yearId)  $query->where('academic_year_id', $yearId);
        if ($classId) $query->where('id', $classId);
        return $query->count();
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  ATTENDANCE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * attendance_records links via student_id + class_id (original schema).
     * If enrollment_id column exists (patched), we use it via the enrollment relationship.
     * We handle both cases gracefully.
     */
    protected function getGlobalAttendanceRate($yearId = null, $classId = null): float
    {
        $query = AttendanceRecord::query();

        if ($yearId) {
            // Filter through enrollments to scope by academic year
            $enrolledStudentIds = StudentEnrollment::where('academic_year_id', $yearId)
                ->when($classId, fn($q) => $q->where('class_id', $classId))
                ->pluck('student_id');
            $query->whereIn('student_id', $enrolledStudentIds);
        } elseif ($classId) {
            $query->where('class_id', $classId);
        }

        $total = $query->count();
        if ($total === 0) return 0;

        $present = (clone $query)->where('status', 'present')->count();
        return round(($present / $total) * 100, 2);
    }

    private function getWeeklyAttendanceTrend($yearId, $classId): array
    {
        // Determine which student IDs to scope
        $studentIds = null;
        if ($yearId) {
            $studentIds = StudentEnrollment::where('academic_year_id', $yearId)
                ->when($classId, fn($q) => $q->where('class_id', $classId))
                ->pluck('student_id');
        }

        $dbDriver = DB::getDriverName();

        if ($dbDriver === 'sqlite') {
            $dayExpr  = "CAST(strftime('%w', date) AS INTEGER)";
            $nameExpr = "CASE strftime('%w', date)
                WHEN '1' THEN 'Mon' WHEN '2' THEN 'Tue' WHEN '3' THEN 'Wed'
                WHEN '4' THEN 'Thu' WHEN '5' THEN 'Fri'
                ELSE 'Other' END";
        } else {
            // MySQL / MariaDB
            $dayExpr  = 'DAYOFWEEK(date)';
            $nameExpr = "CASE DAYOFWEEK(date)
                WHEN 2 THEN 'Mon' WHEN 3 THEN 'Tue' WHEN 4 THEN 'Wed'
                WHEN 5 THEN 'Thu' WHEN 6 THEN 'Fri'
                ELSE 'Other' END";
        }

        $query = AttendanceRecord::query()
            ->selectRaw("$nameExpr as day, $dayExpr as day_order,
                ROUND(AVG(CASE WHEN status = 'present' THEN 100.0 ELSE 0 END), 1) as rate")
            ->whereRaw($dbDriver === 'sqlite'
                ? "strftime('%w', date) NOT IN ('0','6')"
                : 'DAYOFWEEK(date) NOT IN (1,7)')
            ->groupByRaw($dbDriver === 'sqlite'
                ? "strftime('%w', date)"
                : 'DAYOFWEEK(date)')
            ->orderByRaw($dbDriver === 'sqlite'
                ? "strftime('%w', date)"
                : 'DAYOFWEEK(date)');

        if ($studentIds !== null) {
            $query->whereIn('student_id', $studentIds);
        }

        return $query->get()->toArray();
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  GRADES / PERFORMANCE
    // ─────────────────────────────────────────────────────────────────────────

protected function getInstitutionalProficiencyRate($yearId = null, $classId = null): float
{
    $enrollmentIds = $this->getEnrollmentIds($yearId, $classId);
 
    // Build base query scoped to the right enrollments
    $query = \App\Models\Grade::query()
        ->select('student_id', \Illuminate\Support\Facades\DB::raw('AVG(score) as avg_score'))
        ->groupBy('student_id');
 
    if ($enrollmentIds !== null) {
        if ($enrollmentIds->isEmpty()) return 0.0;
        $query->whereIn('enrollment_id', $enrollmentIds);
    }
 
    // Pull averages into PHP and count — avoids the whereHas+havingRaw bug
    $averages = $query->pluck('avg_score');
 
    if ($averages->isEmpty()) return 0.0;
 
    $total     = $averages->count();
    $proficient = $averages->filter(fn($avg) => (float) $avg >= 75)->count();
 
    return round(($proficient / $total) * 100, 1);
}

    protected function getPerformanceTrend($yearId = null, $classId = null): array
    {
        $enrollmentIds = $this->getEnrollmentIds($yearId, $classId);

        $query = Grade::select('term', DB::raw('ROUND(AVG(score), 2) as avg_score'))
            ->whereNotNull('term')
            ->groupBy('term')
            ->orderBy('term');

        if ($enrollmentIds !== null) {
            $query->whereIn('enrollment_id', $enrollmentIds);
        }

        return $query->get()
            ->map(fn($item) => [
                'date'      => $item->term,
                'avg_score' => (float) $item->avg_score,
            ])
            ->toArray();
    }

    private function getSubjectPerformance($yearId, $classId): array
    {
        $enrollmentIds = $this->getEnrollmentIds($yearId, $classId);

        $query = Grade::query()
            ->join('subjects', 'grades.subject_id', '=', 'subjects.id')
            ->selectRaw('subjects.name as subject, ROUND(AVG(grades.score), 1) as score')
            ->groupBy('subjects.id', 'subjects.name')
            ->orderByDesc('score');

        if ($enrollmentIds !== null) {
            $query->whereIn('grades.enrollment_id', $enrollmentIds);
        }

        return $query->get()->toArray();
    }

    private function getGradeDistribution($yearId, $classId): array
    {
        $enrollmentIds = $this->getEnrollmentIds($yearId, $classId);

        $query = Grade::query();
        if ($enrollmentIds !== null) {
            $query->whereIn('enrollment_id', $enrollmentIds);
        }

        $scores = $query->pluck('score');

        return [
            ['name' => 'A (90-100)', 'value' => $scores->filter(fn($s) => $s >= 90)->count()],
            ['name' => 'B (80-89)',  'value' => $scores->filter(fn($s) => $s >= 80 && $s < 90)->count()],
            ['name' => 'C (70-79)',  'value' => $scores->filter(fn($s) => $s >= 70 && $s < 80)->count()],
            ['name' => 'D (60-69)',  'value' => $scores->filter(fn($s) => $s >= 60 && $s < 70)->count()],
            ['name' => 'F (<60)',    'value' => $scores->filter(fn($s) => $s < 60)->count()],
        ];
    }

    private function getSubjectHighlights($yearId, $classId): array
    {
        $performance = collect($this->getSubjectPerformance($yearId, $classId));

        if ($performance->isEmpty()) {
            return ['best' => null, 'most_improved' => null, 'needs_attention' => null];
        }

        $best           = $performance->sortByDesc('score')->first();
        $needsAttention = $performance->sortBy('score')->first();

        $mostImproved = null;
        $previousYear = $yearId
            ? AcademicYear::where('id', '<', $yearId)->orderByDesc('id')->first()
            : null;

        if ($previousYear) {
            $prevPerf = collect($this->getSubjectPerformance($previousYear->id, null))
                ->keyBy('subject');

            $mostImproved = $performance
                ->map(function ($item) use ($prevPerf) {
                    $prev = $prevPerf->get($item['subject']);
                    $item['improvement'] = $prev ? $item['score'] - $prev['score'] : 0;
                    return $item;
                })
                ->sortByDesc('improvement')
                ->first();
        }

        return [
            'best'           => $best           ? ['name' => $best['subject'],           'score'       => $best['score']]                               : null,
            'most_improved'  => $mostImproved   ? ['name' => $mostImproved['subject'],   'improvement' => round($mostImproved['improvement'], 1)]       : null,
            'needs_attention'=> $needsAttention ? ['name' => $needsAttention['subject'], 'score'       => $needsAttention['score']]                     : null,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  RANKINGS
    // ─────────────────────────────────────────────────────────────────────────

    protected function getTopPerformingClasses($yearId = null, $classId = null): array
    {
        $query = SchoolClass::query();
        if ($yearId)  $query->where('academic_year_id', $yearId);
        if ($classId) $query->where('id', $classId);

        return $query->get()
            ->map(function ($class) use ($yearId) {
                $enrollmentIds = StudentEnrollment::where('class_id', $class->id)
                    ->when($yearId, fn($q) => $q->where('academic_year_id', $yearId))
                    ->pluck('id');

                $avgScore = Grade::whereIn('enrollment_id', $enrollmentIds)->avg('score') ?? 0;

                return [
                    'name'          => trim($class->name . ' ' . $class->section),
                    'avg_score'     => round($avgScore, 2),
                    'student_count' => $enrollmentIds->count(),
                ];
            })
            ->sortByDesc('avg_score')
            ->take(5)
            ->values()
            ->toArray();
    }

    protected function getBestStudents($yearId = null, $classId = null, $segment = null): array
    {
        if ($yearId) {
            $enrollmentQuery = StudentEnrollment::where('academic_year_id', $yearId)
                ->with('student.user', 'schoolClass');
            if ($classId) $enrollmentQuery->where('class_id', $classId);

            $enrollments = $enrollmentQuery->get();

            return $enrollments->map(function ($enrollment) use ($segment) {
                $avgScore = Grade::where('enrollment_id', $enrollment->id)->avg('score') ?? 0;

                if ($segment === 'High Performers' && $avgScore < 85) return null;
                if ($segment === 'At Risk'         && $avgScore >= 60) return null;

                return [
                    'name'  => $enrollment->student?->user?->name ?? 'Unknown',
                    'gpa'   => round($avgScore, 2),
                    'class' => $enrollment->schoolClass
                                ? trim($enrollment->schoolClass->name . ' ' . $enrollment->schoolClass->section)
                                : 'N/A',
                ];
            })
            ->filter()
            ->sortByDesc('gpa')
            ->take(5)
            ->values()
            ->toArray();
        }

        // No year: use students table
        $query = Student::with(['user', 'schoolClass']);
        if ($classId) $query->where('class_id', $classId);

        return $query->get()
            ->map(function ($student) use ($segment) {
                $avgScore = Grade::where('student_id', $student->id)->avg('score') ?? 0;

                if ($segment === 'High Performers' && $avgScore < 85) return null;
                if ($segment === 'At Risk'         && $avgScore >= 60) return null;

                return [
                    'name'  => $student->user?->name ?? 'Unknown',
                    'gpa'   => round($avgScore, 2),
                    'class' => $student->schoolClass
                                ? trim($student->schoolClass->name . ' ' . $student->schoolClass->section)
                                : 'N/A',
                ];
            })
            ->filter()
            ->sortByDesc('gpa')
            ->take(5)
            ->values()
            ->toArray();
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  CHARTS
    // ─────────────────────────────────────────────────────────────────────────

    protected function getStudentsByClassCount($yearId = null, $classId = null): array
    {
        $query = SchoolClass::query();
        if ($yearId)  $query->where('academic_year_id', $yearId);
        if ($classId) $query->where('id', $classId);

        return $query->get()
            ->map(function ($class) use ($yearId) {
                $count = StudentEnrollment::where('class_id', $class->id)
                    ->when($yearId, fn($q) => $q->where('academic_year_id', $yearId))
                    ->distinct('student_id')->count('student_id');
                return [
                    'class_name' => trim($class->name . ' ' . $class->section),
                    'count'      => $count,
                ];
            })
            ->sortByDesc('count')
            ->take(7)
            ->values()
            ->toArray();
    }

    protected function getRegistrationTrend(): array
    {
        return StudentEnrollment::select('academic_year', DB::raw('COUNT(DISTINCT student_id) as count'))
            ->groupBy('academic_year')
            ->orderBy('academic_year', 'asc')
            ->get()
            ->map(fn($item) => [
                'academic_year' => $item->academic_year,
                'count'         => (int) $item->count,
            ])
            ->toArray();
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  FEEDBACK — REAL ONLY, NO MOCK FALLBACK
    // ─────────────────────────────────────────────────────────────────────────

    protected function getRealFeedback(): array
    {
        return \App\Models\Feedback::with('user')
            ->latest()
            ->take(5)
            ->get()
            ->map(fn($f) => [
                'name' => $f->user?->name   ?? 'Anonymous',
                'role' => ucfirst($f->user?->role ?? 'user'),
                'msg'  => $f->message,
                'time' => $f->created_at->diffForHumans(),
            ])
            ->toArray();
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  FINANCE
    // ─────────────────────────────────────────────────────────────────────────

    protected function getFinanceOverview(): array
    {
        $total = Payment::sum('amount');
        $paid  = Payment::where('status', 'paid')->sum('amount');
        return [
            'total'           => $total,
            'paid'            => $paid,
            'collection_rate' => $total > 0 ? round(($paid / $total) * 100, 2) : 0,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  TEACHER OVERVIEW
    // ─────────────────────────────────────────────────────────────────────────

    public function teacherOverview(Request $request)
    {
        $teacher = $request->user()->teacher;
        if (!$teacher) return response()->json(['error' => 'Teacher profile not found'], 404);

        $activeYearId = AcademicYear::where('is_active', true)->value('id');

        $classes = SchoolClass::whereHas('subjects', fn($q) => $q->where('teacher_id', $teacher->id))
            ->when($activeYearId, fn($q) => $q->where('academic_year_id', $activeYearId))
            ->withCount('students')
            ->get();

        $pendingGrading = HomeworkSubmission::whereHas('homework', fn($q) =>
                $q->where('teacher_id', $teacher->id)
            )
            ->where('status', 'submitted')
            ->with(['homework', 'student.user'])
            ->get();

        $teacherHomework = Homework::where('teacher_id', $teacher->id)
            ->when($activeYearId, fn($q) =>
                $q->whereHas('schoolClass', fn($sq) => $sq->where('academic_year_id', $activeYearId))
            )
            ->with(['schoolClass' => fn($q) => $q->withCount('students')])
            ->get();

        $totalPossible  = $teacherHomework->sum(fn($hw) => $hw->schoolClass?->students_count ?? 0);
        $totalSubmitted = HomeworkSubmission::whereIn('homework_id', $teacherHomework->pluck('id'))
            ->whereIn('status', ['submitted', 'graded'])
            ->count();

        $homeworkCompletion = $totalPossible > 0
            ? round(($totalSubmitted / $totalPossible) * 100, 2)
            : 0;

        return response()->json([
            'metrics' => [
                'class_attendance'    => $this->getGlobalAttendanceRate(),
                'at_risk_students'    => Insight::whereIn('severity', ['high', 'medium'])->count(),
                'homework_completion' => $homeworkCompletion,
            ],
            'classes'          => $classes,
            'pending_grading'  => $pendingGrading->take(4),
            'recent_activity'  => Announcement::where('user_id', $request->user()->id)->latest()->take(5)->get(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  STUDENT OVERVIEW
    // ─────────────────────────────────────────────────────────────────────────

    public function studentOverview(Request $request)
    {
        $student = $request->user()->student;
        if (!$student) return response()->json(['error' => 'Student profile not found'], 404);

        $currentEnrollment = $student->currentEnrollment;
        if (!$currentEnrollment) return response()->json(['error' => 'No active enrollment found'], 404);

        $exams = \App\Models\Exam::where('class_id', $student->class_id)
            ->where('date', '>=', now())
            ->with('subject')
            ->get();

        $assignments = Homework::where('class_id', $student->class_id)
            ->with(['subject', 'submissions' => fn($q) => $q->where('student_id', $student->id)])
            ->get();

        $courses = \App\Models\ClassSubjectTeacher::where('class_id', $student->class_id)
            ->with(['subject', 'teacher.user'])
            ->get()
            ->map(fn($cst) => [
                'id'      => $cst->subject?->id,
                'name'    => $cst->subject?->name    ?? 'Unknown Subject',
                'code'    => $cst->subject?->code    ?? 'N/A',
                'teacher' => $cst->teacher,
            ]);

        $schedules = \App\Models\Schedule::where('class_id', $student->class_id)
            ->with('subject')
            ->get();

        return response()->json([
            'metrics' => [
                'attendance_rate' => $this->getStudentAttendanceRate($student->id, $currentEnrollment->id),
                'gpa'             => $this->getStudentGPA($student->id, $currentEnrollment->id),
            ],
            'attendance_trend' => $this->getStudentAttendanceTrend($student->id, $currentEnrollment->id),
            'grades'      => Grade::where('student_id', $student->id)
                                  ->where('enrollment_id', $currentEnrollment->id)
                                  ->with('subject')->latest()->get(),
            'exams'       => $exams,
            'assignments' => $assignments,
            'schedules'   => $schedules,
            'insights'    => Insight::where('related_entity_id', $student->id)->where('scope', 'student')->get(),
            'courses'     => $courses,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  PARENT OVERVIEW
    // ─────────────────────────────────────────────────────────────────────────

    public function parentOverview(Request $request)
    {
        $parent    = $request->user()->parent;
        $studentId = $request->query('student_id');

        if (!$parent) return response()->json(['error' => 'Parent profile not found'], 404);

        $students = $parent->students()->where('students.status', 'active')->get();
        if ($students->isEmpty()) return response()->json(['error' => 'No active linked children'], 403);

        $targetStudentId = $studentId ?: $students->first()->id;

        if (!$students->pluck('id')->contains($targetStudentId)) {
            return response()->json(['error' => 'Unauthorized or student is inactive'], 403);
        }

        $student     = Student::with(['user', 'schoolClass', 'currentEnrollment'])->find($targetStudentId);
        $enrollmentId = $student->currentEnrollment?->id;

        return response()->json([
            'current_student' => [
                'user'               => $student->user,
                'school_class'       => $student->schoolClass,
                'current_enrollment' => $student->currentEnrollment,
                'grades'             => Grade::where('student_id', $targetStudentId)
                                            ->when($enrollmentId, fn($q) => $q->where('enrollment_id', $enrollmentId))
                                            ->with('subject')->get(),
            ],
            'metrics' => [
                'attendance_rate' => $this->getStudentAttendanceRate($targetStudentId, $enrollmentId),
                'gpa'             => $this->getStudentGPA($targetStudentId, $enrollmentId),
            ],
            'exams'         => \App\Models\Exam::where('class_id', $student->class_id)->where('date', '>=', now())->get(),
            'schedules'     => \App\Models\Schedule::where('class_id', $student->class_id)->with('subject')->get(),
            'attendance'    => AttendanceRecord::where('student_id', $student->id)
                                ->when($enrollmentId, fn($q) => $q->where('enrollment_id', $enrollmentId))
                                ->latest()->take(30)->get(),
            'assignments'   => Homework::where('class_id', $student->class_id)
                ->with(['subject', 'teacher.user', 'submissions' => fn($q) => $q->where('student_id', $targetStudentId)])
                ->get(),
            'announcements' => Announcement::latest()->take(3)->get(),
            'insights'      => Insight::where('related_entity_id', $targetStudentId)->where('scope', 'student')->get(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  HISTORICAL RECORDS
    // ─────────────────────────────────────────────────────────────────────────

    public function getHistoricalRecords(Request $request)
    {
        $availableYears = StudentPromotion::select('from_academic_year')
            ->distinct()->pluck('from_academic_year')->toArray();

        $studentHistory  = Student::with(['user', 'schoolClass', 'parents.user'])
            ->whereIn('status', ['active', 'unenrolled', 'alumni'])
            ->get()->groupBy('status');

        $teacherHistory  = Teacher::with('user')->get()->groupBy('status');
        $promotionLogs   = StudentPromotion::with(['student.user', 'fromClass', 'toClass'])->latest()->get();
        $promotionStats  = StudentPromotion::select('from_academic_year', 'status', DB::raw('count(*) as count'))
            ->groupBy('from_academic_year', 'status')->get()->groupBy('from_academic_year');

        return response()->json([
            'overview' => [
                'total_alumni'         => Student::where('status', 'alumni')->count(),
                'total_left_students'  => Student::where('status', 'unenrolled')->count(),
                'total_left_teachers'  => Teacher::where('status', 'inactive')->count(),
            ],
            'students'       => [
                'active'    => $studentHistory->get('active', []),
                'unenrolled'=> $studentHistory->get('unenrolled', []),
                'alumni'    => $studentHistory->get('alumni', []),
            ],
            'teachers'       => [
                'active'   => $teacherHistory->get('active', []),
                'inactive' => $teacherHistory->get('inactive', []),
            ],
            'promotions'     => $promotionStats,
            'promotion_logs' => $promotionLogs,
            'years'          => array_values(array_unique($availableYears)),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  STUDENT-LEVEL HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    protected function getStudentAttendanceRate($studentId, $enrollmentId = null): float
    {
        $query = AttendanceRecord::where('student_id', $studentId);
        if ($enrollmentId) $query->where('enrollment_id', $enrollmentId);
        $total = $query->count();
        if ($total === 0) return 0;
        $present = (clone $query)->where('status', 'present')->count();
        return round(($present / $total) * 100, 2);
    }

    protected function getStudentGPA($studentId, $enrollmentId = null): float
    {
        $query = Grade::where('student_id', $studentId);
        if ($enrollmentId) $query->where('enrollment_id', $enrollmentId);
        return round($query->avg('score') ?? 0, 2);
    }

    protected function getStudentAttendanceTrend($studentId, $enrollmentId = null)
    {
        $query = AttendanceRecord::where('student_id', $studentId);
        if ($enrollmentId) $query->where('enrollment_id', $enrollmentId);
        return $query->orderBy('date', 'desc')->take(30)->get(['date', 'status']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  SHARED UTILITY
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns a collection of enrollment IDs matching the given year/class filters,
     * or null if no filters are set (meaning "all records, no scoping").
     */
    private function getEnrollmentIds($yearId, $classId): ?\Illuminate\Support\Collection
    {
        if (!$yearId && !$classId) return null;

        return StudentEnrollment::query()
            ->when($yearId,  fn($q) => $q->where('academic_year_id', $yearId))
            ->when($classId, fn($q) => $q->where('class_id', $classId))
            ->pluck('id');
    }
}