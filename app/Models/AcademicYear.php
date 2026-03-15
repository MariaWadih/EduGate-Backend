<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
    }
}
