<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Material;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MaterialController extends Controller
{
    public function index(Request $request)
    {
        $classId = $request->query('class_id');
        $subjectId = $request->query('subject_id');
        
        $user = $request->user();
        if ($user->role === 'student') {
            $student = $user->student;
            $currentEnrollment = $student?->currentEnrollment;
            
            // If student doesn't provide class_id, use their current one
            if (!$classId && $currentEnrollment) {
                $classId = $currentEnrollment->class_id;
            }
        }
        
        $materials = Material::query();

        if ($classId) {
            $materials->where('class_id', $classId);
        }

        if ($subjectId) {
            $materials->where('subject_id', $subjectId);
        }

        return response()->json($materials->with(['subject', 'schoolClass', 'teacher.user'])->latest()->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'class_id' => 'required|exists:classes,id',
            'subject_id' => 'required|exists:subjects,id',
            'section' => 'nullable|string|max:255',
            'sub_section' => 'nullable|string|max:255',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'file' => 'required|file|max:20480', // 20MB max
        ]);

        $teacher = $request->user()->teacher;
        if (!$teacher) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Verify teacher teaches this subject in this class
        $exists = DB::table('class_subject_teacher')
            ->where('class_id', $validated['class_id'])
            ->where('subject_id', $validated['subject_id'])
            ->where('teacher_id', $teacher->id)
            ->exists();

        if (!$exists) {
            return response()->json(['message' => 'You are not assigned to teach this subject in this class.'], 403);
        }

        $file = $request->file('file');
        $fileName = $file->getClientOriginalName();
        $filePath = $file->store('materials', 'public');
        $fileType = $file->getClientOriginalExtension();

        $material = Material::create([
            'class_id' => $validated['class_id'],
            'subject_id' => $validated['subject_id'],
            'teacher_id' => $teacher->id,
            'section' => $validated['section'],
            'sub_section' => $validated['sub_section'],
            'title' => $validated['title'] ?? $fileName,
            'description' => $validated['description'],
            'file_path' => $filePath,
            'file_name' => $fileName,
            'file_type' => $fileType,
        ]);

        return response()->json($material, 201);
    }

    public function destroy($id)
    {
        $material = Material::findOrFail($id);
        
        // Only teacher who created it or admin can delete
        $user = request()->user();
        if ($user->role !== 'admin' && (!$user->teacher || $material->teacher_id !== $user->teacher->id)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($material->file_path) {
            Storage::disk('public')->delete($material->file_path);
        }
        
        $material->delete();
        return response()->json(null, 204);
    }

    public function download(Request $request)
    {
        $path = $request->query('path');
        if (!$path || !Storage::disk('public')->exists($path)) {
            return response()->json(['message' => 'File not found'], 404);
        }

        return Storage::disk('public')->download($path);
    }

    public function downloadAllByCourse(Request $request)
    {
        $classId = $request->query('class_id');
        $subjectId = $request->query('subject_id');

        if (!$classId || !$subjectId) {
            return response()->json(['message' => 'class_id and subject_id are required'], 400);
        }

        // Fetch all materials for this course
        $materials = Material::where('class_id', $classId)
            ->where('subject_id', $subjectId)
            ->with('subject')
            ->get();

        if ($materials->isEmpty()) {
            return response()->json(['message' => 'No materials found for this course'], 404);
        }

        // Create a unique temporary zip file
        $zipFileName = 'course_materials_' . time() . '.zip';
        $zipPath = storage_path('app/temp/' . $zipFileName);
        
        // Ensure temp directory exists
        if (!file_exists(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0755, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return response()->json(['message' => 'Failed to create zip file'], 500);
        }

        // Add each material file to the zip
        foreach ($materials as $material) {
            $filePath = storage_path('app/public/' . $material->file_path);
            if (file_exists($filePath)) {
                // Use the original filename or material title
                $fileNameInZip = $material->file_name;
                
                // Add section prefix if available for better organization
                if ($material->section) {
                    $fileNameInZip = $material->section . '/' . $fileNameInZip;
                }
                if ($material->sub_section) {
                    $fileNameInZip = str_replace($material->section . '/', $material->section . '/' . $material->sub_section . '/', $fileNameInZip);
                }
                
                $zip->addFile($filePath, $fileNameInZip);
            }
        }

        $zip->close();

        // Get subject name for download filename
        $subjectName = $materials->first()->subject->name ?? 'course';
        $downloadName = str_replace(' ', '_', $subjectName) . '_materials.zip';

        // Return the file as download and delete after sending
        return response()->download($zipPath, $downloadName)->deleteFileAfterSend(true);
    }
}
