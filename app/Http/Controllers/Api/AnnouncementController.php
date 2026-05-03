<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AcademicYear;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function index(Request $request)
    {
        $user      = $request->user();
        $activeYear = AcademicYear::active();

        // No active year = nothing to show
        if (!$activeYear) {
            return response()->json([]);
        }

        $query = Announcement::with(['user', 'targetClass'])
            ->where('academic_year_id', $activeYear->id);

        if ($user->role === 'student') {
            $classId = $user->student?->class_id;
            $query->where(function ($q) use ($classId) {
                $q->whereIn('target_role', ['all', 'student']);
                if ($classId) {
                    $q->orWhere(function ($sq) use ($classId) {
                        $sq->where('target_role', 'class')
                           ->where('target_class_id', $classId);
                    });
                }
            });

        } elseif ($user->role === 'teacher') {
            $query->whereIn('target_role', ['all', 'teacher', 'student', 'class']);

        } elseif ($user->role === 'parent') {
            $studentClassIds = $user->parent->students
                ->pluck('class_id')->filter()->unique();

            $query->where(function ($q) use ($studentClassIds) {
                $q->whereIn('target_role', ['all', 'student', 'parent'])
                  ->orWhere(function ($sq) use ($studentClassIds) {
                      $sq->where('target_role', 'class')
                         ->whereIn('target_class_id', $studentClassIds);
                  });
            });
        }

        return response()->json($query->latest()->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'title'   => 'required|string',
            'message' => 'required|string',
        ]);

        $activeYear = AcademicYear::active();

        if (!$activeYear) {
            return response()->json(['message' => 'No active academic year found.'], 422);
        }

        $announcement = Announcement::create([
            'user_id'          => $request->user()->id,
            'title'            => $request->title,
            'message'          => $request->message,
            'target_role'      => $request->target_role ?? 'all',
            'target_class_id'  => $request->target_class_id,
            'academic_year_id' => $activeYear->id,
        ]);

        return response()->json($announcement, 201);
    }
}
