<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Models\Teacher;

class AcademicYear extends Model
{
    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'is_active',
        'status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'is_active'  => 'boolean',
    ];

    // ── Relationships ──────────────────────────────────────────────

    public function classes()
    {
        return $this->hasMany(SchoolClass::class, 'academic_year_id');
    }

    public function enrollments()
    {
        return $this->hasMany(StudentEnrollment::class, 'academic_year_id');
    }

    public function classSubjectTeachers()
    {
        return $this->hasMany(ClassSubjectTeacher::class, 'academic_year_id');
    }
    public function teachers()
{
    return $this->belongsToMany(Teacher::class, 'teacher_academic_years');
}

    // ── Helpers ────────────────────────────────────────────────────

    /**
     * Get the currently active academic year record.
     */
    public static function active(): ?self
    {
        return static::where('is_active', true)->first();
    }

    /**
     * Get the active year's name string (e.g. "2024-2025").
     * Falls back to the most recent year if none is active.
     */
    public static function activeName(): string
    {
        $year = static::active();
        return $year?->name ?? static::orderBy('name', 'desc')->value('name') ?? date('Y') . '-' . (date('Y') + 1);
    }

    /**
     * Set this year as active and deactivate all others atomically.
     */
    public function activate(): void
{
    static::where('is_active', true)->update(['is_active' => false, 'status' => 'completed']);
    $this->update(['is_active' => true, 'status' => 'active']);

    // Carry over all active teachers into this year
    $activeTeacherIds = Teacher::where('status', 'active')->pluck('id');

    $records = $activeTeacherIds->map(fn($id) => [
        'teacher_id'       => $id,
        'academic_year_id' => $this->id,
        'created_at'       => now(),
        'updated_at'       => now(),
    ])->toArray();

    DB::table('teacher_academic_years')->insertOrIgnore($records);
}
}
