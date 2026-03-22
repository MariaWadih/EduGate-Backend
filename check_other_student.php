<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Student;
use App\Models\StudentEnrollment;

$student_user = User::where('email', 'student@gmail.com')->first();
if (!$student_user) {
    echo "Student 'student@gmail.com' not found.\n";
    exit;
}
$student = Student::where('user_id', $student_user->id)->first();
echo "Student name: ".$student_user->name.", id: ".$student->id.", class_id: ".$student->class_id.", current_year: '".$student->current_academic_year."'\n";

$enrollments = StudentEnrollment::where('student_id', $student->id)->get();
foreach ($enrollments as $e) {
    echo "  Enrollment: year '".$e->academic_year."', class_id: ".$e->class_id.", status: ".$e->status."\n";
}
