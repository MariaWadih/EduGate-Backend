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
        return $this->from('attendanceschool02@gmail.com', 'Attendance Office')
                    ->subject('Action Required: Attendance Warning for ' . $this->student->user->name)
                    ->html("
                        <div style='font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e1e9f1; border-radius: 12px;'>
                            <div style='background: #fdf2f2; padding: 20px; border-radius: 10px; text-align: center; margin-bottom: 24px;'>
                                <span style='font-size: 40px;'>⚠️</span>
                                <h1 style='color: #9b1c1c; margin-top: 10px;'>Attendance Warning</h1>
                            </div>
                            <p style='color: #374151; font-size: 16px;'>Dear Parent/Guardian,</p>
                            <p style='color: #4b5563; line-height: 1.6;'>This is an official notification regarding the attendance record for <strong>{$this->student->user->name}</strong>.</p>
                            <div style='background: #f9fafb; padding: 16px; border-left: 4px solid #d1d5db; margin: 24px 0;'>
                                <p style='margin: 0; color: #111827; font-weight: 600;'>Current Summary:</p>
                                <p style='margin: 5px 0 0 0; color: #9b1c1c; font-size: 20px; font-weight: 800;'>{$this->absences} Unexcused Absences</p>
                            </div>
                            <p style='color: #4b5563; line-height: 1.6;'>According to our records, <strong>{$this->absences}</strong> unexcused absences have been accumulated for the current academic session. Excessive absenteeism can significantly impact academic performance and may lead to mandatory review by the administration.</p>
                            <p style='color: #4b5563; line-height: 1.6;'>If this absence was due to a medical or personal emergency, please provide valid documentation to the registrar to update our records accordingly.</p>
                            <div style='margin-top: 32px; padding-top: 20px; border-top: 1px solid #f3f4f6; color: #9ca3af; font-size: 14px;'>
                                <p style='margin-bottom: 4px;'>School Administration Intelligence Service</p>
                                <p>EduGate Management Suite</p>
                            </div>
                        </div>
                    ");
    }
}
