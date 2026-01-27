<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Recommendation;
use App\Models\Student;
use App\Models\User;
use App\Models\UserParent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class ParentController extends Controller
{
    public function index()
    {
        return UserParent::with(['user', 'students.user'])->get();
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'student_ids' => 'nullable|array',
            'student_ids.*' => 'exists:students,id',
        ]);

        return DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'parent',
            ]);

            $parent = UserParent::create(['user_id' => $user->id]);

            if ($request->has('student_ids')) {
                foreach ($request->student_ids as $studentId) {
                    $parent->students()->attach($studentId, ['relationship_type' => 'guardian']);
                }
            }

            return response()->json($parent->load(['user', 'students.user']), 201);
        });
    }

    public function update(Request $request, $id)
    {
        $parent = UserParent::findOrFail($id);
        $user = $parent->user;

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:8',
            'student_ids' => 'nullable|array',
            'student_ids.*' => 'exists:students,id',
        ]);

        return DB::transaction(function () use ($request, $parent, $user) {
            $user->update([
                'name' => $request->name,
                'email' => $request->email,
            ]);

            if ($request->filled('password')) {
                $user->update(['password' => Hash::make($request->password)]);
            }

            if ($request->has('student_ids')) {
                $syncData = [];
                foreach ($request->student_ids as $id) {
                    $syncData[$id] = ['relationship_type' => 'guardian'];
                }
                $parent->students()->sync($syncData);
            }

            return response()->json($parent->load(['user', 'students.user']));
        });
    }

    public function destroy($id)
    {
        $parent = UserParent::findOrFail($id);
        $parent->user()->delete();
        return response()->json(['message' => 'Parent deleted successfully']);
    }

    public function getChildren(Request $request)
    {
        $parent = $request->user()->parent;
        if (!$parent) return response()->json([], 404);

        return response()->json($parent->students()->with('user', 'schoolClass')->get());
    }

    public function getRecommendations(Request $request)
    {
        $parent = $request->user()->parent;
        $studentId = $request->query('student_id');

        if (!$parent) return response()->json([], 404);

        $query = Recommendation::where('parent_id', $parent->id);
        if ($studentId) {
            $query->where('student_id', $studentId);
        }

        return response()->json($query->latest()->get());
    }

    public function storeRecommendation(Request $request)
    {
        $parent = $request->user()->parent;
        if (!$parent) return response()->json(['message' => 'Parent only'], 403);

        $request->validate([
            'student_id' => 'required|exists:students,id',
            'category' => 'required|string',
            'message' => 'required|string',
        ]);

        // Verify relationship
        if (!$parent->students()->where('students.id', $request->student_id)->exists()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $recommendation = Recommendation::create([
            'parent_id' => $parent->id,
            'student_id' => $request->student_id,
            'category' => $request->category,
            'message' => $request->message,
            'visibility' => 'all'
        ]);

        return response()->json($recommendation, 201);
    }
}
