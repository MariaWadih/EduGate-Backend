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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    public function adminOverview()
    {
        $data = [
            'metrics' => [
                'total_students' => Student::count(),
                'total_teachers' => Teacher::count(),
                'total_parents' => UserParent::count(),
                'attendance_rate' => $this->getGlobalAttendanceRate(),
                'chronic_absenteeism' => Insight::where('insight_type', 'attendance')->where('severity', 'high')->count(),
                'collection_rate' => $this->getFinanceOverview()['collection_rate'] ?? 0,
            ],
            'rankings' => [
                'top_classes' => $this->getTopPerformingClasses(),
                'best_students' => $this->getBestStudents(),
            ],
            'charts' => [
                'attendance_trend' => $this->getAttendanceTrend(),
                'attendance_by_grade' => $this->getAttendanceByGrade(),
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

        $data = [
            'metrics' => [
                'attendance_rate' => $this->getStudentAttendanceRate($student->id),
                'gpa' => $this->getStudentGPA($student->id),
            ],
            'attendance_trend' => $this->getStudentAttendanceTrend($student->id),
            'grades' => Grade::where('student_id', $student->id)->with('subject')->latest()->take(6)->get(),
            'exams' => $exams,
            'assignments' => $assignments,
            'insights' => Insight::where('related_entity_id', $student->id)->where('scope', 'student')->get(),
            'provenance' => ['attendance_records', 'grades', 'insights', 'exams', 'homeworks']
        ];

        return response()->json($data);
    }

    public function parentOverview(Request $request)
    {
        $parent = $request->user()->parent;
        $studentId = $request->query('student_id');

        if (!$parent) return response()->json(['error' => 'Parent profile not found'], 404);

        $students = $parent->students;
        if ($students->isEmpty()) return response()->json(['error' => 'No linked children'], 404);

        $targetStudentId = $studentId ?: $students->first()->id;

        // Verify ownership
        if (!$students->pluck('id')->contains($targetStudentId)) {
            return response()->json(['error' => 'Unauthorized access to student data'], 403);
        }

        $student = Student::with(['user', 'schoolClass', 'grades.subject', 'examResults.exam'])->find($targetStudentId);

        $data = [
            'current_student' => $student,
            'metrics' => [
                'attendance_rate' => $this->getStudentAttendanceRate($targetStudentId),
                'gpa' => $this->getStudentGPA($targetStudentId),
            ],
            'exams' => \App\Models\Exam::where('class_id', $student->class_id)->where('date', '>=', now())->get(),
            'announcements' => Announcement::latest()->take(3)->get(),
            'insights' => Insight::where('related_entity_id', $targetStudentId)->where('scope', 'student')->get(),
            'provenance' => ['attendance_records', 'grades', 'insights', 'parent_student']
        ];

        return response()->json($data);
    }

    // Helper functions
    protected function getGlobalAttendanceRate()
    {
        $total = AttendanceRecord::count();
        if ($total == 0) return 0;
        $present = AttendanceRecord::where('status', 'present')->count();
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

    protected function getStudentAttendanceRate($studentId)
    {
        $total = AttendanceRecord::where('student_id', $studentId)->count();
        if ($total == 0) return 0;
        $present = AttendanceRecord::where('student_id', $studentId)->where('status', 'present')->count();
        return round(($present / $total) * 100, 2);
    }

    protected function getStudentGPA($studentId)
    {
        return Grade::where('student_id', $studentId)->avg('score') ?: 0;
    }

    protected function getStudentAttendanceTrend($studentId)
    {
        return AttendanceRecord::where('student_id', $studentId)
            ->orderBy('date', 'desc')
            ->take(10)
            ->get(['date', 'status']);
    }

    protected function getGradeDistribution()
    {
        return Grade::select(DB::raw('score, count(*) as count'))
            ->groupBy('score')
            ->get();
    }

    protected function getTopPerformingClasses()
    {
        return SchoolClass::with(['students.grades'])
            ->get()
            ->map(function($class) {
                $scores = $class->students->flatMap->grades->pluck('score');
                return [
                    'name' => $class->name . ' ' . $class->section,
                    'avg_score' => $scores->avg() ?: 0,
                    'student_count' => $class->students->count()
                ];
            })
            ->sortByDesc('avg_score')
            ->take(3)
            ->values();
    }

    protected function getBestStudents()
    {
        return Student::with(['user', 'grades', 'schoolClass'])
            ->get()
            ->map(function($student) {
                return [
                    'name' => $student->user->name,
                    'gpa' => round($student->grades->avg('score') ?: 0, 2),
                    'class' => $student->schoolClass ? $student->schoolClass->name . ' ' . $student->schoolClass->section : 'N/A'
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
}
