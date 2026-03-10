<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Exam extends Model
{
    protected $fillable = [
        'class_id', 
        'subject_id', 
        'teacher_id',
        'title', 
        'description',
        'type',
        'start_time',
        'end_time',
        'duration_minutes',
        'file_path',
        'file_name',
        'max_score',
        'date'
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
    ];

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function questions()
    {
        return $this->hasMany(ExamQuestion::class);
    }

    public function submissions()
    {
        return $this->hasMany(ExamSubmission::class);
    }
}
