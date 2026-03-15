<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Announcement;
use App\Models\User;

$user = User::where('name', 'Frank Wright')->first();
$query = Announcement::with(['user', 'targetClass']);

if ($user->role === 'student') {
    $student = $user->student;
    $classId = $student ? $student->class_id : null;
    
    $query->where(function($q) use ($classId) {
        $q->where('target_role', 'all')
          ->orWhere('target_role', 'student')
          ->orWhere(function($sq) use ($classId) {
              $sq->where('target_role', 'class')
                 ->where('target_class_id', $classId);
          });
    });
}

$results = $query->latest()->get();
echo json_encode($results, JSON_PRETTY_PRINT);
