<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = ['student_id', 'amount', 'status', 'due_date', 'type', 'payment_date'];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
