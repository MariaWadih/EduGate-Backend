<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use Illuminate\Http\Request;

class AcademicYearController extends Controller
{
    /**
     * List all academic years.
     */
    public function index()
    {
        return response()->json(AcademicYear::orderBy('name', 'desc')->get());
    }

    /**
     * Create a new academic year.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name'       => 'required|string|unique:academic_years,name',
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after:start_date',
            'status'     => 'nullable|in:upcoming,active,completed',
        ]);

        $year = AcademicYear::create([
            'name'       => $request->name,
            'start_date' => $request->start_date,
            'end_date'   => $request->end_date,
            'is_active'  => false,
            'status'     => $request->status ?? 'upcoming',
        ]);

        return response()->json($year, 201);
    }

    /**
     * Update an academic year's details.
     */
    public function update(Request $request, $id)
    {
        $year = AcademicYear::findOrFail($id);

        $request->validate([
            'name'       => 'sometimes|string|unique:academic_years,name,' . $id,
            'start_date' => 'sometimes|date',
            'end_date'   => 'sometimes|date',
            'status'     => 'sometimes|in:upcoming,active,completed',
        ]);

        $year->update($request->only(['name', 'start_date', 'end_date', 'status']));

        return response()->json($year);
    }

    /**
     * Set a specific academic year as the active one.
     * Deactivates all others automatically.
     */
    public function activate($id)
    {
        $year = AcademicYear::findOrFail($id);
        $year->activate();

        return response()->json([
            'message'       => "Academic year '{$year->name}' is now active.",
            'academic_year' => $year->fresh(),
        ]);
    }

    /**
     * Get the currently active academic year.
     */
    public function active()
    {
        $year = AcademicYear::active();

        if (!$year) {
            return response()->json(['message' => 'No active academic year set'], 404);
        }

        return response()->json($year);
    }

    /**
     * Get all records for a specific academic year.
     */
    public function records($id)
    {
        $year = AcademicYear::with([
            'enrollments.student.user',
            'enrollments.schoolClass',
            'classSubjectTeachers.teacher.user',
            'classSubjectTeachers.subject',
            'classSubjectTeachers.schoolClass'
        ])->findOrFail($id);

        return response()->json($year);
    }
}
