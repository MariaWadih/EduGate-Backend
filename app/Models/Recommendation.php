<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Recommendation extends Model
{
    protected $fillable = ['parent_id', 'student_id', 'category', 'message', 'visibility'];

    public function parent()
    {
        return $this->belongsTo(UserParent::class, 'parent_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
