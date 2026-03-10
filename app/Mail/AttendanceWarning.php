<?php

namespace App\Mail;

use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AttendanceWarning extends Mailable
{
    use Queueable, SerializesModels;

    public $student;
    public $absences;

    public function __construct(Student $student, $absences)
    {
        $this->student = $student;
        $this->absences = $absences;
    }

    public function build()
    {
        return $this->subject('Attendance Warning: Excessive Unexcused Absences')
                    ->html("
                        <h1>Attendance Warning</h1>
                        <p>Dear Parent/Student,</p>
                        <p>This is an automated warning regarding the attendance of <strong>{$this->student->user->name}</strong>.</p>
                        <p>Our records show that the student has accumulated <strong>{$this->absences}</strong> unexcused absences during the current academic year.</p>
                        <p>Please note that exceeding the allowable limit of absences may lead to academic consequences. We encourage you to provide valid excuses for future absences.</p>
                        <p>Thank you,</p>
                        <p>School Administration</p>
                    ");
    }
}
