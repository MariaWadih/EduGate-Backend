<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use Illuminate\Http\Request;

class FeedbackController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if ($user->role === 'admin') {
            return response()->json(Feedback::with('user')->latest()->get());
        }
        return response()->json(Feedback::where('user_id', $user->id)->latest()->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            'type' => 'nullable|string',
            'subject' => 'nullable|string',
        ]);

        $feedback = Feedback::create([
            'user_id' => $request->user()->id,
            'type' => $request->type ?? 'feedback',
            'subject' => $request->subject,
            'message' => $request->message,
        ]);

        return response()->json($feedback, 201);
    }

    public function update(Request $request, $id)
    {
        $feedback = Feedback::findOrFail($id);
        
        // Only admin can mark as read
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $feedback->update($request->only('is_read'));
        return response()->json($feedback);
    }

    public function destroy($id)
    {
        $feedback = Feedback::findOrFail($id);
        $feedback->delete();
        return response()->json(null, 204);
    }
}
