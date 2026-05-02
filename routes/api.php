<?php

use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\PromotionController;
use App\Http\Controllers\Api\AcademicYearController;


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClassController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\GradeController;
use App\Http\Controllers\Api\HomeworkController;
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\ParentController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\InsightController;
use App\Http\Controllers\Api\TeacherController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\AcademicController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\MaterialController;


// Public routes
Route::post('/login', [AuthController::class, 'login']);

// Health check
Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'db' => 'connected',
        'timestamp' => now()
    ]);
});

Route::get('/teachers/list', fn() => response()->json(
    \App\Models\Teacher::with('user')->get()->map(fn($t) => [
        'id'   => $t->id,
        'name' => $t->user->name
    ])
));

// Public — active academic year (needed before login for display)
Route::get('/academic-years/active', [AcademicYearController::class, 'active']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);

    // Admin Only
    Route::middleware('role:admin')->group(function () {
        Route::get('/teachers/past', [TeacherController::class, 'past']);
        Route::post('/teachers/{id}/reactivate', [TeacherController::class, 'reactivate']);
        Route::post('/users/register', [AuthController::class, 'register']);
        Route::apiResource('/classes', ClassController::class)->except(['show']);
        Route::apiResource('/subjects', SubjectController::class);
        Route::apiResource('/teachers', TeacherController::class);
        Route::apiResource('/parents', ParentController::class);
        Route::apiResource('/students', StudentController::class);
        Route::patch('/students/{id}/status', [StudentController::class, 'updateStatus']);
        Route::patch('/teachers/{id}/status', [TeacherController::class, 'updateStatus']);
        Route::get('/academic-hierarchy', [AcademicController::class, 'index']);
        Route::post('/academic/grade-subject', [AcademicController::class, 'storeGradeSubject']);
        Route::post('/academic/grade', [AcademicController::class, 'storeGrade']);
        Route::post('/academic/section', [AcademicController::class, 'storeSection']);
        Route::delete('/academic/grade', [AcademicController::class, 'destroyGrade']);
        Route::delete('/academic/section/{id}', [AcademicController::class, 'destroySection']);
        Route::delete('/academic/grade-subject', [AcademicController::class, 'destroyGradeSubject']);
        Route::put('/academic/grade', [AcademicController::class, 'updateGrade']);
        Route::put('/academic/section/{id}', [AcademicController::class, 'updateSection']);

        // Student Promotions
        Route::get('/promotions/candidates', [PromotionController::class, 'getCandidates']);
        Route::post('/promotions/promote', [PromotionController::class, 'promoteStudents']);
        Route::post('/promotions/bulk-promote-class', [PromotionController::class, 'bulkPromoteClass']);
        Route::post('/promotions/initialize-classes', [PromotionController::class, 'initializeTargetClasses']);
        Route::get('/promotions/student/{studentId}/history', [PromotionController::class, 'getStudentHistory']);
        Route::get('/promotions/year/{academicYear}/statistics', [PromotionController::class, 'getYearStatistics']);
        Route::get('/promotions/classes-for-year', [PromotionController::class, 'getClassesForYear']);
        Route::put('/academic/subject/{id}', [AcademicController::class, 'updateSubject']);
        Route::apiResource('/schedules', ScheduleController::class);

        // Academic Years Management
        Route::get('/academic-years', [AcademicYearController::class, 'index']);
        Route::post('/academic-years', [AcademicYearController::class, 'store']);
        Route::put('/academic-years/{id}', [AcademicYearController::class, 'update']);
        Route::post('/academic-years/{id}/activate', [AcademicYearController::class, 'activate']);
        Route::get('/academic-years/{id}/records', [AcademicYearController::class, 'records']);
    });







    // Student & Parent (Shared stuff) - Specific paths first to avoid parameter collision
    Route::middleware('role:student,parent,teacher,admin')->group(function () {
        Route::get('/homework/my', [HomeworkController::class, 'myHomework']);
        Route::get('/parent/homework', [HomeworkController::class, 'childHomework']);
        Route::post('/homework/submit', [HomeworkController::class, 'submitHomework']);
        Route::get('/homework/file/download', [HomeworkController::class, 'downloadFile']);
        Route::get('/attendance/my', [AttendanceController::class, 'myAttendance']);
        Route::get('/grades/my', [GradeController::class, 'myGrades']);
        Route::get('/announcements', [AnnouncementController::class, 'index']);
        Route::get('/exams', [ExamController::class, 'index']);
        Route::get('/exams/{id}', [ExamController::class, 'show']);
        Route::post('/exams/{id}/submit', [ExamController::class, 'submit']);
        Route::get('/materials', [MaterialController::class, 'index']);
        Route::get('/materials/download', [MaterialController::class, 'download']);
        Route::get('/materials/download-all', [MaterialController::class, 'downloadAllByCourse']);
    });

    // Admin & Teacher
    Route::middleware('role:admin,teacher')->group(function () {
        Route::get('/classes/{id}', [ClassController::class, 'show']);
        Route::get('/teacher/classes', [ClassController::class, 'teacherClasses']);
        Route::post('/attendance', [AttendanceController::class, 'store']);
        Route::get('/attendance/check', [AttendanceController::class, 'getByClassDate']);
        Route::get('/grades', [GradeController::class, 'index']);
        Route::post('/grades', [GradeController::class, 'store']);
        Route::apiResource('/homework', HomeworkController::class);
        Route::get('/homework/{id}/submissions', [HomeworkController::class, 'getSubmissions']);
        Route::post('/homework/grade', [HomeworkController::class, 'gradeSubmission']);
        Route::post('/exams', [ExamController::class, 'store']);
        Route::put('/exams/{exam}', [ExamController::class, 'update']);
        Route::delete('/exams/{exam}', [ExamController::class, 'destroy']);
        Route::get('/exams/{id}/submissions', [ExamController::class, 'getSubmissions']);
        Route::post('/exams/grade', [ExamController::class, 'gradeSubmission']);
        Route::post('/announcements', [AnnouncementController::class, 'store']);
        Route::post('/materials', [MaterialController::class, 'store']);
        Route::delete('/materials/{id}', [MaterialController::class, 'destroy']);
        Route::get('/attendance/student/{studentId}', [AttendanceController::class, 'studentAttendance']);
    });

    // Parent Specific
    Route::middleware('role:parent')->group(function () {
        Route::get('/parent/children', [ParentController::class, 'getChildren']);
        Route::get('/parent/recommendations', [ParentController::class, 'getRecommendations']);
        Route::post('/parent/recommendations', [ParentController::class, 'storeRecommendation']);
        Route::get('/parent/children/{studentId}/grades', [GradeController::class, 'childGrades']);
        Route::get('/parent/children/{studentId}/attendance', [AttendanceController::class, 'childAttendance']);
        Route::get('/parent/exams', [ExamController::class, 'getExamsforParents']);
    });

    // Analytics
    Route::prefix('analytics')->group(function () {
        Route::get('/admin/overview', [AnalyticsController::class, 'adminOverview'])->middleware('role:admin');
        Route::get('/classes', [ClassController::class, 'index']);
        Route::get('/teachers', [TeacherController::class, 'index'])->middleware('role:admin');
        Route::get('/students', [StudentController::class, 'index'])->middleware('role:admin');
        Route::get('/parents', [ParentController::class, 'index'])->middleware('role:admin');
        Route::get('/teacher/overview', [AnalyticsController::class, 'teacherOverview'])->middleware('role:teacher');
        Route::get('/student/overview', [AnalyticsController::class, 'studentOverview'])->middleware('role:student');
        Route::get('/parent/overview', [AnalyticsController::class, 'parentOverview'])->middleware('role:parent');
        Route::get('/admin/history', [AnalyticsController::class, 'getHistoricalRecords'])->middleware('role:admin');
    });

    // Insights
    Route::get('/insights', [InsightController::class, 'index']);

    // Feedback
    Route::apiResource('/feedback', FeedbackController::class);

    //ai assistant
    Route::get('/schedule/my', [ScheduleController::class, 'mySchedule']);

});
