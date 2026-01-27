<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Grade;
use App\Models\HomeworkSubmission;
use App\Models\Insight;
use App\Models\Student;
use Carbon\Carbon;

class InsightsService
{
    public function generateDailyInsights()
    {
        $students = Student::all();

        foreach ($students as $student) {
            $this->checkAttendanceRules($student);
            $this->checkPerformanceRules($student);
            $this->checkHomeworkRules($student);
        }

        // School-wide insights
        $this->checkSchoolWideTrends();
    }

    protected function checkAttendanceRules(Student $student)
    {
        // Rule: Attendance < 85% (last 30 days)
        $last30Days = Carbon::now()->subDays(30);
        $totalDays = AttendanceRecord::where('student_id', $student->id)
            ->where('date', '>=', $last30Days)
            ->count();

        if ($totalDays > 0) {
            $presentDays = AttendanceRecord::where('student_id', $student->id)
                ->where('date', '>=', $last30Days)
                ->where('status', 'present')
                ->count();

            $rate = ($presentDays / $totalDays) * 100;

            if ($rate < 85) {
                Insight::updateOrCreate(
                    ['insight_type' => 'attendance', 'related_entity_id' => $student->id, 'scope' => 'student'],
                    [
                        'severity' => 'high',
                        'message' => "At Risk: Attendance. Current rate: " . round($rate, 2) . "%",
                    ]
                );
            }
        }

        // Rule: Absent 3 consecutive days (Simplified check)
        $recentAbsences = AttendanceRecord::where('student_id', $student->id)
            ->orderBy('date', 'desc')
            ->take(3)
            ->get();

        if ($recentAbsences->count() === 3 && $recentAbsences->every(fn($r) => $r->status === 'absent')) {
            Insight::updateOrCreate(
                ['insight_type' => 'attendance', 'related_entity_id' => $student->id, 'scope' => 'student', 'message' => 'Consecutive Absences'],
                ['severity' => 'medium', 'message' => 'Consecutive Absences: Missed 3 days in a row.']
            );
        }
    }

    protected function checkPerformanceRules(Student $student)
    {
        // Rule: Grade below passing threshold (70 for example)
        $lowGrades = Grade::where('student_id', $student->id)
            ->where('score', '<', 70)
            ->get();

        if ($lowGrades->count() > 0) {
            Insight::updateOrCreate(
                ['insight_type' => 'grades', 'related_entity_id' => $student->id, 'scope' => 'student'],
                [
                    'severity' => 'medium',
                    'message' => "Needs Support: Low performance in " . $lowGrades->count() . " records.",
                ]
            );
        }
    }

    protected function checkHomeworkRules(Student $student)
    {
        // Rule: Homework missed pattern
        $missedHomework = HomeworkSubmission::where('student_id', $student->id)
            ->where('status', 'pending')
            ->count();

        if ($missedHomework >= 2) {
            Insight::updateOrCreate(
                ['insight_type' => 'homework', 'related_entity_id' => $student->id, 'scope' => 'student'],
                [
                    'severity' => 'medium',
                    'message' => "Homework Missed Pattern: " . $missedHomework . " assignments pending.",
                ]
            );
        }
    }

    protected function checkSchoolWideTrends()
    {
        // Future: Add school-wide checks
    }
}
