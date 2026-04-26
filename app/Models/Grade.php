<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Grade extends Model
{
    protected $fillable = [
        'student_id',
        'enrollment_id',
        'subject_id',
        'teacher_id',
        'score',
        'max_score',
        'term',
        'comments',
        'date'
    ];

    public function enrollment()
    {
        return $this->belongsTo(StudentEnrollment::class, 'enrollment_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    // ✅ ADD THIS
    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }
}
