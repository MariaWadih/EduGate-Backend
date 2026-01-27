<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\UserParent;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\AttendanceRecord;
use App\Models\Grade;
use App\Models\Announcement;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\Insight;
use Illuminate\Database\Seeder;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create Admin
        User::create([
            'name' => 'System Admin',
            'email' => 'admin@edugate.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        // 2. Create Classes (Grades 9, 10, 11 with sections A, B, C)
        $classes = [];
        $grades = ['Grade 9', 'Grade 10', 'Grade 11'];
        $sections = ['A', 'B', 'C'];
        foreach ($grades as $g) {
            foreach ($sections as $s) {
                $classes[] = SchoolClass::create(['name' => $g, 'section' => $s]);
            }
        }

        // 3. Create Subjects
        $subjectNames = ['Mathematics', 'Physics', 'Chemistry', 'Biology', 'History', 'Literature', 'Computer Science', 'Art'];
        $subjects = [];
        foreach ($subjectNames as $name) {
            $subjects[] = Subject::create(['name' => $name]);
        }

        // 4. Create Teachers and assign subjects to classes properly
        $teacherNames = [
            'John Henderson', 'Sarah Miller', 'Robert Wilson', 'Emily Davis', 
            'Michael Brown', 'Jessica Taylor', 'David Thomas', 'Linda Garcia'
        ];
        $teachers = [];
        foreach ($teacherNames as $i => $name) {
            $user = User::create([
                'name' => $name,
                'email' => strtolower(str_replace(' ', '.', $name)) . '@edugate.com',
                'password' => Hash::make('password'),
                'role' => 'teacher',
            ]);
            $teachers[] = Teacher::create(['user_id' => $user->id]);
        }

        // Ensure every class has subjects
        foreach ($classes as $class) {
            // Assign 4-6 random subjects to each class
            $classSubjects = array_rand($subjects, rand(4, 6));
            foreach ($classSubjects as $sIdx) {
                $teacher = $teachers[$sIdx % count($teachers)];
                $class->subjects()->attach($subjects[$sIdx]->id, ['teacher_id' => $teacher->id]);
            }
        }

        // 5. Create Students

        $studentNames = [
            'Alice Smith', 'Bob Johnson', 'Charlie Brown', 'David Lee', 'Eve Wilson',
            'Frank Wright', 'Grace Hopper', 'Henry Ford', 'Ivy Chen', 'Jack Sparrow',
            'Katie Perry', 'Liam Neeson', 'Mia Khalifa', 'Noah Ark', 'Olivia Pope',
            'Peter Parker', 'Quinn Fabray', 'Riley Reid', 'Sophia Loren', 'Tony Stark',
            'Uma Thurman', 'Victor Hugo', 'Wanda Maximoff', 'Xena Warrior', 'Yara Greyjoy', 'Zane Grey'
        ];
        $students = [];
        foreach ($studentNames as $name) {
            $user = User::create([
                'name' => $name,
                'email' => strtolower(str_replace(' ', '.', $name)) . '@student.com',
                'password' => Hash::make('password'),
                'role' => 'student',
            ]);
            $students[] = Student::create([
                'user_id' => $user->id,
                'class_id' => $classes[array_rand($classes)]->id,
            ]);
        }

        // 6. Create Parents
        foreach ($students as $i => $student) {
            if ($i % 3 === 0) { // One parent for every 3 students
                $user = User::create([
                    'name' => "Parent of " . $student->user->name,
                    'email' => "parent" . $i . "@edugate.com",
                    'password' => Hash::make('password'),
                    'role' => 'parent',
                ]);
                $parent = UserParent::create(['user_id' => $user->id]);
                $parent->students()->attach($student->id, ['relationship_type' => 'guardian']);
                
                if (isset($students[$i+1])) {
                    $parent->students()->attach($students[$i+1]->id, ['relationship_type' => 'guardian']);
                }
            }
        }

        // 7. Generate Grades & Attendance
        foreach ($students as $student) {
            // Attendance
            for ($i = 0; $i < 15; $i++) {
                AttendanceRecord::create([
                    'student_id' => $student->id,
                    'class_id' => $student->class_id,
                    'date' => Carbon::now()->subDays($i)->toDateString(),
                    'status' => rand(0, 10) > 1 ? 'present' : 'absent',
                ]);
            }

            // Grades for each subject assigned to their class
            $classSubjects = $student->schoolClass->subjects;
            foreach ($classSubjects as $subject) {
                Grade::create([
                    'student_id' => $student->id,
                    'subject_id' => $subject->id,
                    'teacher_id' => $subject->pivot->teacher_id,
                    'score' => rand(65, 98),
                    'term' => 'Term 1',
                    'comments' => 'Demonstrates good understanding.',
                ]);
            }

            // Payments
            Payment::create([
                'student_id' => $student->id,
                'amount' => 1200.00,
                'status' => rand(0, 5) > 1 ? 'paid' : 'pending',
                'due_date' => Carbon::now()->addDays(rand(1, 30)),
                'payment_date' => Carbon::now()->subDays(rand(1, 10)),
                'type' => 'Tuition Fee',
            ]);
        }

        // 8. Generate System Insights
        Insight::create([
            'insight_type' => 'attendance',
            'severity' => 'high',
            'message' => 'Attendance dropped by 12% in Grade 11 C this week.',
            'scope' => 'admin',
        ]);

        Insight::create([
            'insight_type' => 'performance',
            'severity' => 'medium',
            'message' => 'Mathematics average score is 15% lower than Chemistry.',
            'scope' => 'admin',
        ]);

        // 9. Announcement
        Announcement::create([
            'user_id' => 1,
            'title' => 'Welcome to the New Academic Year',
            'message' => 'We are excited to have all students back on campus.',
            'target_role' => 'student',
        ]);

        // 10. Schedules
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        $slots = [
            ['start' => '08:00', 'end' => '09:30'],
            ['start' => '09:45', 'end' => '11:15'],
            ['start' => '11:30', 'end' => '13:00'],
            ['start' => '14:00', 'end' => '15:30'],
            ['start' => '15:45', 'end' => '17:15'],
        ];

        foreach ($classes as $class) {
            $assignedSubjects = $class->subjects;
            if ($assignedSubjects->isEmpty()) continue;

            foreach ($days as $day) {
                // Assign 2-3 subjects per day from the ones assigned to this class
                $dailySubjects = $assignedSubjects->random(min($assignedSubjects->count(), rand(2, 3)));
                
                foreach ($dailySubjects->values() as $idx => $subject) {
                    $slot = $slots[$idx % count($slots)];
                    
                    Schedule::create([
                        'class_id' => $class->id,
                        'subject_id' => $subject->id,
                        'day_of_week' => $day,
                        'start_time' => $slot['start'],
                        'end_time' => $slot['end'],
                        'room' => 'Room ' . rand(100, 500),
                    ]);
                }
            }
        }
    }
}
