<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentEnrollment extends Model
{
    protected $fillable = [
        'student_id',
        'class_id',
        'academic_year',
        'academic_year_id',
        'status',
        'enrollment_date',
        'notes'
    ];

    protected $casts = [
        'enrollment_date' => 'date'
    ];

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class, 'academic_year_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function attendanceRecords()
    {
        return $this->hasMany(AttendanceRecord::class, 'enrollment_id');
    }

    public function grades()
    {
        return $this->hasMany(Grade::class, 'enrollment_id');
    }

    public function homeworkSubmissions()
    {
        return $this->hasMany(HomeworkSubmission::class, 'enrollment_id');
    }

    public function examResults()
    {
        return $this->hasMany(ExamResult::class, 'enrollment_id');
    }
}
