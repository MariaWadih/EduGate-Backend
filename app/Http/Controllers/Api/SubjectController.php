<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use Illuminate\Http\Request;

class SubjectController extends Controller
{
    public function index()
    {
        return response()->json(Subject::all());
    }

    public function store(Request $request)
    {
        $request->validate(['name' => 'required|string|unique:subjects']);
        $subject = Subject::create($request->all());
        return response()->json($subject, 201);
    }
}
