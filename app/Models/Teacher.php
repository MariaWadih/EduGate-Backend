<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Teacher extends Model
{
    protected $fillable = ['user_id', 'status', 'joined_at'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function assignments()
    {
        return $this->hasMany(ClassSubjectTeacher::class);
    }

    public function homeworks()
    {
        return $this->hasMany(Homework::class);
    }
    public function academicYears()
{
    return $this->belongsToMany(AcademicYear::class, 'teacher_academic_years');
}
}
