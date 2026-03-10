<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamSubmission extends Model
{
    protected $fillable = [
        'exam_id', 
        'student_id', 
        'status', 
        'mcq_answers', 
        'file_path', 
        'file_name', 
        'score', 
        'teacher_feedback',
        'started_at',
        'submitted_at',
        'graded_at'
    ];

    protected $casts = [
        'mcq_answers' => 'array',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'graded_at' => 'datetime',
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
