<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserParent extends Model
{
    protected $table = 'parents';
    protected $fillable = ['user_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function students()
    {
        return $this->belongsToMany(Student::class, 'parent_student', 'parent_id', 'student_id')
                    ->withPivot('relationship_type')
                    ->withTimestamps();
    }

    public function recommendations()
    {
        return $this->hasMany(Recommendation::class, 'parent_id');
    }
}
