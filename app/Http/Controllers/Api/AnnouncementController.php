<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        
        $query = Announcement::with(['user', 'targetClass']);

        if ($user->role === 'student') {
            $student = $user->student;
            $query->where(function($q) use ($student) {
                $q->where('target_role', 'student')
                  ->orWhere('target_role', 'all');
            })->where(function($q) use ($student) {
                $q->where('target_class_id', $student->class_id)
                  ->orWhereNull('target_class_id');
            });
        } elseif ($user->role === 'parent') {
            $studentIds = $user->parent->students->pluck('id');
            // Simplified for now, just show shared/all
            $query->whereIn('target_role', ['all', 'student', 'parent']);
        }

        return response()->json($query->latest()->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string',
            'message' => 'required|string',
        ]);

        $announcement = Announcement::create([
            'user_id' => $request->user()->id,
            'title' => $request->title,
            'message' => $request->message,
            'target_role' => $request->target_role ?? 'all',
            'target_class_id' => $request->target_class_id,
        ]);

        return response()->json($announcement, 201);
    }
}
