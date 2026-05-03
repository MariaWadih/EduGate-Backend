<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use App\Models\AcademicYear;
use Illuminate\Http\Request;

class FeedbackController extends Controller
{
    public function index(Request $request)
    {
        $user       = $request->user();
        $activeYear = AcademicYear::active();

        if (!$activeYear) {
            return response()->json([]);
        }

        $query = Feedback::with('user')
            ->where('academic_year_id', $activeYear->id)
            ->latest();

        if ($user->role !== 'admin') {
            $query->where('user_id', $user->id);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            'type'    => 'nullable|string',
            'subject' => 'nullable|string',
        ]);

        $activeYear = AcademicYear::active();

        if (!$activeYear) {
            return response()->json(['message' => 'No active academic year found.'], 422);
        }

        $feedback = Feedback::create([
            'user_id'          => $request->user()->id,
            'type'             => $request->type ?? 'feedback',
            'subject'          => $request->subject,
            'message'          => $request->message,
            'academic_year_id' => $activeYear->id,
        ]);

        return response()->json($feedback, 201);
    }

    public function update(Request $request, $id)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $feedback = Feedback::findOrFail($id);
        $feedback->update($request->only('is_read'));
        return response()->json($feedback);
    }

    public function destroy($id)
    {
        Feedback::findOrFail($id)->delete();
        return response()->json(null, 204);
    }
}