<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    protected $fillable = ['user_id', 'title', 'message', 'target_role', 'target_class_id', 'academic_year_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function targetClass()
    {
        return $this->belongsTo(SchoolClass::class, 'target_class_id');
    }

    public function academicYear()
{
    return $this->belongsTo(AcademicYear::class);
}
}
