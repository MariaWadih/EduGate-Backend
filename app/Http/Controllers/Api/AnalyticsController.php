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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    public function adminOverview(Request $request)
    {
            $yearId = $request->query('academic_year_id');


        
        $data = [
            'metrics' => [
                'total_students' => $yearId ? \App\Models\StudentEnrollment::where('academic_year_id', $yearId)->count() : Student::count(),
                'total_teachers' => $yearId ? Teacher::whereHas('assignments', function($q) use ($yearId) {
                    $q->where('academic_year_id', $yearId);
                })->count() : Teacher::count(),
                'total_parents' => UserParent::count(),
                'total_classes' => $yearId ? SchoolClass::where('academic_year_id', $yearId)->count() : SchoolClass::count(),
                'total_subjects' => $yearId ? \App\Models\ClassSubjectTeacher::where('academic_year_id', $yearId)->distinct('subject_id')->count() : \App\Models\Subject::count(),
                'attendance_rate' => $this->getGlobalAttendanceRate($yearId),
                'chronic_absenteeism' => Insight::where('insight_type', 'attendance')->where('severity', 'high')->count(),
                'collection_rate' => $this->getFinanceOverview()['collection_rate'] ?? 0,
            ],
            'rankings' => [
                'top_classes' => $this->getTopPerformingClasses($yearId),
                'best_students' => $this->getBestStudents($yearId),
            ],
            'charts' => [
                'performance_trend' => $this->getPerformanceTrend($yearId),
                'students_by_class' => $this->getStudentsByClassCount($yearId),
                'registration_trend' => $this->getRegistrationTrend(),
            ],
            'insights' => Insight::latest()->take(5)->get(),
            'announcements' => Announcement::latest()->take(3)->get(),
            'feedback' => $this->getDynamicFeedback(),
            'computed_at' => now(),
        ];

        return response()->json($data);
    }

    public function teacherOverview(Request $request)
    {
        $teacher = $request->user()->teacher;
        if (!$teacher) return response()->json(['error' => 'Teacher profile not found'], 404);

        // Real classes for this teacher
        $classes = SchoolClass::whereHas('subjects', function($q) use ($teacher) {
            $q->where('teacher_id', $teacher->id);
        })->withCount('students')->get();

        // Pending grading (submissions with 'submitted' status)
        $pendingGrading = HomeworkSubmission::whereHas('homework', function($q) use ($teacher) {
            $q->where('teacher_id', $teacher->id);
        })->where('status', 'submitted')->with(['homework', 'student.user'])->get();

        $data = [
            'metrics' => [
                'class_attendance' => $this->getGlobalAttendanceRate(),
                'at_risk_students' => Insight::whereIn('severity', ['high', 'medium'])->count(),
                'homework_completion' => 65,
            ],
            'classes' => $classes,
            'pending_grading' => $pendingGrading->take(4),
            'recent_activity' => Announcement::where('user_id', $request->user()->id)->latest()->take(5)->get(),
            'provenance' => ['attendance_records', 'insights', 'homework_submissions', 'classes']
        ];

        return response()->json($data);
    }

    public function studentOverview(Request $request)
    {
        $student = $request->user()->student;
        if (!$student) return response()->json(['error' => 'Student profile not found'], 404);

        // Real upcoming exams (placeholder logic based on exams table)
        $exams = \App\Models\Exam::where('class_id', $student->class_id)
            ->where('date', '>=', now())
            ->with('subject')
            ->get();

        // Real homework assignments
        $assignments = Homework::where('class_id', $student->class_id)
            ->with(['subject', 'submissions' => function($q) use ($student) {
                $q->where('student_id', $student->id);
            }])
            ->get();

        // Add courses (subjects) with teachers
        $courses = \App\Models\ClassSubjectTeacher::where('class_id', $student->class_id)
            ->with(['subject', 'teacher.user'])
            ->get()
            ->map(function($cst) {
                return [
                    'id' => $cst->subject ? $cst->subject->id : null,
                    'name' => $cst->subject ? $cst->subject->name : 'Unknown Subject',
                    'code' => $cst->subject ? $cst->subject->code : 'N/A',
                    'teacher' => $cst->teacher
                ];
            });

        // Real class schedules
        $schedules = \App\Models\Schedule::where('class_id', $student->class_id)
            ->with('subject')
            ->get();

        $currentEnrollment = $student->currentEnrollment;
        if (!$currentEnrollment) return response()->json(['error' => 'No active enrollment found'], 404);

        $data = [
            'metrics' => [
                'attendance_rate' => $this->getStudentAttendanceRate($student->id, $currentEnrollment->id),
                'gpa' => $this->getStudentGPA($student->id, $currentEnrollment->id),
            ],
            'attendance_trend' => $this->getStudentAttendanceTrend($student->id, $currentEnrollment->id),
            'grades' => Grade::where('student_id', $student->id)->where('enrollment_id', $currentEnrollment->id)->with('subject')->latest()->get(),
            'exams' => $exams,
            'assignments' => $assignments,
            'schedules' => $schedules,
            'insights' => Insight::where('related_entity_id', $student->id)->where('scope', 'student')->get(),
            'courses' => $courses,
            'provenance' => ['attendance_records', 'grades', 'insights', 'exams', 'homeworks', 'subjects', 'schedules']
        ];

        return response()->json($data);
    }

    public function parentOverview(Request $request)
    {
        $parent = $request->user()->parent;
        $studentId = $request->query('student_id');

        if (!$parent) return response()->json(['error' => 'Parent profile not found'], 404);

        $students = $parent->students()->where('students.status', 'active')->get();
        if ($students->isEmpty()) return response()->json(['error' => 'No active linked children'], 403);

        $targetStudentId = $studentId ?: $students->first()->id;

        // Verify ownership and activity
        if (!$students->pluck('id')->contains($targetStudentId)) {
            return response()->json(['error' => 'Unauthorized or student is inactive'], 403);
        }

        $student = Student::with(['user', 'schoolClass', 'currentEnrollment'])->find($targetStudentId);
        $enrollmentId = $student->currentEnrollment?->id;

        $data = [
            'current_student' => [
                'user' => $student->user,
                'school_class' => $student->schoolClass,
                'current_enrollment' => $student->currentEnrollment,
                'grades' => Grade::where('student_id', $targetStudentId)
                            ->when($enrollmentId, fn($q) => $q->where('enrollment_id', $enrollmentId))
                            ->with('subject')
                            ->get()
            ],
            'metrics' => [
                'attendance_rate' => $this->getStudentAttendanceRate($targetStudentId, $enrollmentId),
                'gpa' => $this->getStudentGPA($targetStudentId, $enrollmentId),
            ],
            'exams' => \App\Models\Exam::where('class_id', $student->class_id)->where('date', '>=', now())->get(),
            'schedules' => \App\Models\Schedule::where('class_id', $student->class_id)->with('subject')->get(),
            'attendance' => \App\Models\AttendanceRecord::where('student_id', $student->id)
                            ->when($enrollmentId, fn($q) => $q->where('enrollment_id', $enrollmentId))
                            ->latest()->take(30)->get(),
            'assignments' => \App\Models\Homework::where('class_id', $student->class_id)
                ->with(['subject', 'teacher.user', 'submissions' => function($q) use ($targetStudentId) {
                    $q->where('student_id', $targetStudentId);
                }])->get(),
            'announcements' => Announcement::latest()->take(3)->get(),
            'insights' => Insight::where('related_entity_id', $targetStudentId)->where('scope', 'student')->get(),
            'provenance' => ['attendance_records', 'grades', 'insights', 'parent_student', 'schedules', 'homeworks']
        ];

        return response()->json($data);
    }

    // Helper functions
protected function getPerformanceTrend($yearId = null)
{
    $query = Grade::select(DB::raw('term, avg(score) as avg_score'));

    if ($yearId) {
        $query->whereHas('enrollment', function($q) use ($yearId) {
            $q->where('academic_year_id', $yearId);
        });
    }

    return $query->groupBy('term')
        ->orderBy('term', 'asc')
        ->get()
        ->map(function($item) {
            return [
                'date' => $item->term,
                'avg_score' => round($item->avg_score, 2)
            ];
        });
}

    protected function getStudentsByClassCount($yearId = null)
    {
        $query = SchoolClass::withCount('students');
        
        if ($yearId) {
            $query->where('academic_year_id', $yearId);
        }

        return $query->get()
            ->map(function($class) {
                return [
                    'class_name' => $class->name . ' ' . $class->section,
                    'count' => $class->students_count
                ];
            })
            ->sortByDesc('count')
            ->take(5)
            ->values();
    }

    protected function getGlobalAttendanceRate($yearId = null)
    {
        $query = AttendanceRecord::query();
        if ($yearId) {
            $query->whereHas('enrollment', function($q) use ($yearId) {
                $q->where('academic_year_id', $yearId);
            });
        }

        $total = $query->count();
        if ($total == 0) return 0;
        $present = (clone $query)->where('status', 'present')->count();
        return round(($present / $total) * 100, 2);
    }

    protected function getAttendanceTrend()
    {
        return AttendanceRecord::select(DB::raw('date, count(*) as total, sum(case when status="present" then 1 else 0 end) as present'))
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->take(7)
            ->get()
            ->map(function($item) {
                return [
                    'date' => $item->date,
                    'rate' => $item->total > 0 ? round(($item->present / $item->total) * 100, 2) : 0
                ];
            });
    }

    protected function getStudentAttendanceRate($studentId, $enrollmentId = null)
    {
        $query = AttendanceRecord::where('student_id', $studentId);
        if ($enrollmentId) {
            $query->where('enrollment_id', $enrollmentId);
        }
        
        $total = $query->count();
        if ($total == 0) return 0;
        
        $present = (clone $query)->where('status', 'present')->count();
        return round(($present / $total) * 100, 2);
    }

    protected function getStudentGPA($studentId, $enrollmentId = null)
    {
        $terms = ['Test 1', 'Test 2', 'Exam 1', 'Test 3', 'Exam 2'];
        $query = Grade::where('student_id', $studentId)
            ->whereIn('term', $terms);
            
        if ($enrollmentId) {
            $query->where('enrollment_id', $enrollmentId);
        }
        
        $grades = $query->get();
        if ($grades->isEmpty()) return 0;

        // Group by subject, then calculate average across the 5 standard pillars for each subject
        $subjectOverallScores = $grades->groupBy('subject_id')
            ->map(function($subjectGrades) use ($terms) {
                // Sum only the 5 pillars and divide by 5 as per standard evaluation structure
                $sum = $subjectGrades->sum('score');
                return $sum / count($terms);
            });
            
        return round($subjectOverallScores->avg(), 1);
    }

    protected function getStudentAttendanceTrend($studentId, $enrollmentId = null)
    {
        $query = AttendanceRecord::where('student_id', $studentId);
        if ($enrollmentId) {
            $query->where('enrollment_id', $enrollmentId);
        }
        
        return $query->orderBy('date', 'desc')
            ->get(['date', 'status', 'remarks']);
    }

    protected function getGradeDistribution()
    {
        return Grade::select(DB::raw('score, count(*) as count'))
            ->groupBy('score')
            ->get();
    }

protected function getTopPerformingClasses($yearId = null)
{
    $query = SchoolClass::query();
    if ($yearId) {
        $query->where('academic_year_id', $yearId);
    }

    return $query->with(['students.grades' => function($q) use ($yearId) {
            if ($yearId) {
                $q->whereHas('enrollment', function($eq) use ($yearId) {
                    $eq->where('academic_year_id', $yearId);
                });
            }
        }])
        ->get()
        ->map(function($class) {
            $scores = $class->students
                ->flatMap->grades
                ->pluck('score')
                ->filter();

            return [
                'name' => $class->name . ' ' . $class->section,
                'avg_score' => round($scores->avg() ?: 0, 2),
                'student_count' => $class->students->count()
            ];
        })
        ->sortByDesc('avg_score')
        ->take(3)
        ->values();
}

protected function getBestStudents($yearId = null)
{
    $query = Student::with(['user', 'schoolClass']);

    if ($yearId) {
        $query->whereHas('enrollments', function($q) use ($yearId) {
            $q->where('academic_year_id', $yearId);
        });
    }

    return $query->get()
        ->map(function($student) use ($yearId) {
            $scores = Grade::where('student_id', $student->id)
                ->when($yearId, function($q) use ($yearId) {
                    $q->whereHas('enrollment', function($eq) use ($yearId) {
                        $eq->where('academic_year_id', $yearId);
                    });
                })
                ->pluck('score')
                ->filter();

            return [
                'name' => $student->user->name,
                'gpa' => round($scores->avg() ?: 0, 2),
                'class' => $student->schoolClass
                    ? $student->schoolClass->name . ' ' . $student->schoolClass->section
                    : 'N/A'
            ];
        })
        ->sortByDesc('gpa')
        ->take(5)
        ->values();
}

    protected function getAttendanceByGrade()
    {
        return SchoolClass::with(['attendanceRecords'])
            ->get()
            ->groupBy('name')
            ->map(function($group, $gradeName) {
                $total = $group->flatMap->attendanceRecords->count();
                $present = $group->flatMap->attendanceRecords->where('status', 'present')->count();
                return [
                    'grade' => $gradeName,
                    'rate' => $total > 0 ? round(($present / $total) * 100, 2) : 0
                ];
            })
            ->values();
    }

    protected function getRegistrationTrend()
    {
        return User::select(DB::raw('strftime("%Y-%m", created_at) as month, count(*) as count'))
            ->groupBy('month')
            ->orderBy('month', 'asc')
            ->take(6)
            ->get();
    }

    protected function getFinanceOverview()
    {
        $total = \App\Models\Payment::sum('amount');
        $paid = \App\Models\Payment::where('status', 'paid')->sum('amount');
        return [
            'total' => $total,
            'paid' => $paid,
            'collection_rate' => $total > 0 ? round(($paid / $total) * 100, 2) : 0
        ];
    }

    protected function getDynamicFeedback()
    {
        $feedbacks = \App\Models\Feedback::with('user')->latest()->take(5)->get();
        
        if ($feedbacks->isEmpty()) {
            return [
                ['name' => 'John Doe', 'role' => 'Student', 'msg' => 'The new assignment portal is much easier to use.', 'time' => '2 hours ago'],
                ['name' => 'Jane Smith', 'role' => 'Teacher', 'msg' => 'I appreciate the quick response to my grading query.', 'time' => '5 hours ago'],
                ['name' => 'Robert Brown', 'role' => 'Parent', 'msg' => 'The mobile app updates are very helpful for tracking attendance.', 'time' => '8 hours ago'],
            ];
        }

        return $feedbacks->map(function($f) {
            return [
                'name' => $f->user->name,
                'role' => ucfirst($f->user->role),
                'msg' => $f->message,
                'time' => $f->created_at->diffForHumans()
            ];
        });
    }

    public function getHistoricalRecords(Request $request)
    {
        // Get all unique academic years from promotions to populate filters
        $availableYears = StudentPromotion::select('from_academic_year')
            ->distinct()
            ->pluck('from_academic_year')
            ->toArray();
            
        if (empty($availableYears)) {
            $availableYears = ['2023-2024', '2024-2025'];
        }
        
        $studentHistory = Student::with(['user', 'schoolClass', 'parents.user'])
            ->whereIn('status', ['active', 'unenrolled', 'alumni'])
            ->get()
            ->groupBy('status');

        $teacherHistory = Teacher::with(['user'])
            ->get()
            ->groupBy('status');

        $promotionLogs = StudentPromotion::with(['student.user', 'fromClass', 'toClass'])
            ->latest()
            ->get();

        $promotionStats = StudentPromotion::select('from_academic_year', 'status', DB::raw('count(*) as count'))
            ->groupBy('from_academic_year', 'status')
            ->get()
            ->groupBy('from_academic_year');

        $data = [
            'overview' => [
                'total_alumni' => Student::where('status', 'alumni')->count(),
                'total_left_students' => Student::where('status', 'unenrolled')->count(),
                'total_left_teachers' => Teacher::where('status', 'inactive')->count(),
            ],
            'students' => [
                'active' => $studentHistory->get('active', []),
                'unenrolled' => $studentHistory->get('unenrolled', []),
                'alumni' => $studentHistory->get('alumni', []),
            ],
            'teachers' => [
                'active' => $teacherHistory->get('active', []),
                'inactive' => $teacherHistory->get('inactive', []),
            ],
            'promotions' => $promotionStats,
            'promotion_logs' => $promotionLogs,
            'years' => array_values(array_unique(array_merge($availableYears, ['2023-2024', '2024-2025'])))
        ];

        return response()->json($data);
    }
}
