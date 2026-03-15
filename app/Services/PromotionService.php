<?php

namespace App\Services;

use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentPromotion;
use Illuminate\Support\Facades\DB;

class PromotionService
{
    /**
     * Promote a single student
     */
    public function promoteStudent(int $studentId, array $data)
    {
        return DB::transaction(function () use ($studentId, $data) {
            $student = Student::findOrFail($studentId);

            // 1. Validation: Only Active students can be promoted
            if ($student->status !== 'active') {
                throw new \Exception("Only active students can be promoted. Current status: {$student->status}");
            }

            // 1b. Safety Check: If status is 'promoted', they cannot stay in the same class
            if ($data['status'] === 'promoted' && isset($data['from_class_id']) && $data['to_class_id'] == $data['from_class_id']) {
                throw new \Exception("Student cannot be 'promoted' to the same class. Please select the next grade/section.");
            }

            // 2. Clear current active enrollment for the current year
            $currentEnrollment = StudentEnrollment::where('student_id', $studentId)
                ->where('academic_year', $data['from_academic_year'])
                ->where('status', 'active')
                ->first();

            if ($currentEnrollment) {
                $currentEnrollment->update([
                    'status' => $data['status'], // promoted, retained, etc.
                    'notes' => $data['remarks'] ?? null
                ]);
            }

            // Resolve academic year IDs from strings if they aren't provided
            $fromYearId = $data['from_academic_year_id'] ?? \App\Models\AcademicYear::where('name', $data['from_academic_year'])->value('id');
            $toYearId = $data['to_academic_year_id'] ?? \App\Models\AcademicYear::where('name', $data['to_academic_year'])->value('id');

            // 3. Handle terminal statuses (Graduated, Transferred)
            if (in_array($data['status'], ['graduated', 'transferred'])) {
                $student->update([
                    'status' => $data['status'] === 'graduated' ? 'alumni' : 'unenrolled',
                    'class_id' => null // No longer assigned to an active class
                ]);

                // Create terminal promotion/exit log
                return StudentPromotion::create([
                    'student_id' => $student->id,
                    'from_class_id' => $data['from_class_id'] ?? $student->getOriginal('class_id'),
                    'to_class_id' => null,
                    'from_academic_year' => $data['from_academic_year'],
                    'to_academic_year' => $data['to_academic_year'],
                    'status' => $data['status'],
                    'remarks' => $data['remarks'] ?? 'System processed academic exit',
                    'promotion_date' => now()
                ]);
            }

            // 4. Create new enrollment for the next academic year
            // "Each student can have only one active enrollment per academic year."
            $existingNextEnrollment = StudentEnrollment::where('student_id', $studentId)
                ->where('academic_year', $data['to_academic_year'])
                ->first();

            if ($existingNextEnrollment) {
                throw new \Exception("Student already has an enrollment for academic year {$data['to_academic_year']}");
            }

            $newEnrollment = StudentEnrollment::create([
                'student_id' => $student->id,
                'class_id' => $data['to_class_id'],
                'academic_year' => $data['to_academic_year'],
                'academic_year_id' => $toYearId,
                'status' => 'active',
                'enrollment_date' => now(),
                'notes' => 'Auto-generated through promotion'
            ]);

            // 5. Update student's current class and academic year
            $student->update([
                'class_id' => $data['to_class_id'],
                'current_academic_year' => $data['to_academic_year']
            ]);

            // 6. Create promotion record (legacy/logging)
            StudentPromotion::create([
                'student_id' => $student->id,
                'from_class_id' => $data['from_class_id'] ?? $student->class_id,
                'to_class_id' => $data['to_class_id'],
                'from_academic_year' => $data['from_academic_year'],
                'to_academic_year' => $data['to_academic_year'],
                'status' => $data['status'],
                'remarks' => $data['remarks'] ?? null,
                'promotion_date' => now()
            ]);

            return $newEnrollment;
        });
    }

    /**
     * Bulk promotion
     */
    public function promoteStudents(array $promotions, string $fromYear, string $toYear)
    {
        return DB::transaction(function () use ($promotions, $fromYear, $toYear) {
            $results = [
                'success' => 0,
                'failed' => 0,
                'errors' => []
            ];

            foreach ($promotions as $promo) {
                try {
                    $this->promoteStudent($promo['student_id'], [
                        'from_academic_year' => $fromYear,
                        'to_academic_year' => $toYear,
                        'to_class_id' => $promo['to_class_id'],
                        'status' => $promo['status'], // promoted, retained, etc.
                        'remarks' => $promo['remarks'] ?? null,
                        'from_class_id' => $promo['from_class_id'] ?? null
                    ]);
                    $results['success']++;
                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'student_id' => $promo['student_id'],
                        'error' => $e->getMessage()
                    ];
                }
            }

            return $results;
        });
    }
}
