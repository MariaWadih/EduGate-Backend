<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    $class = \App\Models\SchoolClass::with(['students.user', 'students.parents.user', 'subjects'])->find(1);
    $json = json_encode($class);
    if ($json === false) {
        echo "JSON Encode Error: " . json_last_error_msg() . "\n";
    } else {
        echo "JSON Serialize Success. Length: " . strlen($json) . "\n";
    }
} catch (\Exception $e) {
    echo "Fatal Error: " . $e->getMessage() . "\n";
}
