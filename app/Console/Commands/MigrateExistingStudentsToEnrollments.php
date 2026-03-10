<?php

namespace App\Console\Commands;

use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Grade;
use App\Models\AttendanceRecord;
use App\Models\HomeworkSubmission;
use App\Models\ExamResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateExistingStudentsToEnrollments extends Command
{
    protected $signature = 'school:migrate-enrollments';
    protected $description = 'Migrate existing students to the enrollment system';

    public function handle()
    {
        $this->info('Starting enrollment migration...');

        DB::transaction(function () {
            $students = Student::whereNotNull('current_academic_year')
                ->whereNotNull('class_id')
                ->get();

            $this->info("Found {$students->count()} students to migrate.");

            foreach ($students as $student) {
                // Determine status based on student status
                $status = ($student->status === 'active') ? 'active' : 'promoted'; // Defaulting to promoted if not active for past contexts

                $enrollment = StudentEnrollment::firstOrCreate([
                    'student_id' => $student->id,
                    'academic_year' => $student->current_academic_year,
                ], [
                    'class_id' => $student->class_id,
                    'status' => $student->status === 'active' ? 'active' : 'promoted',
                    'enrollment_date' => $student->created_at ?? now(),
                ]);

                // Link existing records to this enrollment
                Grade::where('student_id', $student->id)->whereNull('enrollment_id')->update(['enrollment_id' => $enrollment->id]);
                AttendanceRecord::where('student_id', $student->id)->whereNull('enrollment_id')->update(['enrollment_id' => $enrollment->id]);
                HomeworkSubmission::where('student_id', $student->id)->whereNull('enrollment_id')->update(['enrollment_id' => $enrollment->id]);
                ExamResult::where('student_id', $student->id)->whereNull('enrollment_id')->update(['enrollment_id' => $enrollment->id]);
            }
        });

        $this->info('Migration completed successfully.');
    }
}
