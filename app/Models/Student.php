<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    protected $fillable = ['user_id', 'class_id', 'status', 'enrolled_at', 'current_academic_year'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function parents()
    {
        return $this->belongsToMany(UserParent::class, 'parent_student', 'student_id', 'parent_id')
                    ->withPivot('relationship_type')
                    ->withTimestamps();
    }

    public function enrollments()
    {
        return $this->hasMany(StudentEnrollment::class);
    }

// Remove both, replace with this single one
public function currentEnrollment()
{
    return $this->hasOne(StudentEnrollment::class)
        ->whereHas('academicYear', fn($q) => $q->where('is_active', true))
        ->where('status', 'active')
        ->latestOfMany('enrollment_date');
}

public function currentGrades()
{
    return $this->hasManyThrough(
        Grade::class,
        StudentEnrollment::class,
        'student_id',
        'enrollment_id'
    )->whereHas('enrollment.academicYear', fn($q) => $q->where('is_active', true));
}

    public function attendance()
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function grades()
    {
        return $this->hasMany(Grade::class);
    }

    public function homeworkSubmissions()
    {
        return $this->hasMany(HomeworkSubmission::class);
    }

    public function examResults()
    {
        return $this->hasMany(ExamResult::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
