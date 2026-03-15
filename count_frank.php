<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = \App\Models\User::where('name', 'Frank Wright')->first();
$student = $user->student;
$count = \App\Models\AttendanceRecord::where('student_id', $student->id)
    ->where('status', 'absent')
    ->where(function ($q) {
        $q->whereNull('remarks')->orWhere('remarks', '');
    })->count();

echo "UNEXCUSED_COUNT:" . $count . "\n";
