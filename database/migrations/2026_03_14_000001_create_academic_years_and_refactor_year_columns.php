<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create academic_years table
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // e.g. "2024-2025"
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(false);
            $table->enum('status', ['upcoming', 'active', 'completed'])->default('upcoming');
            $table->timestamps();
        });

        // 2. Seed academic_years from existing data in classes table
        $existingYears = DB::table('classes')->select('academic_year')->distinct()->pluck('academic_year');
        $enrollmentYears = DB::table('student_enrollments')->select('academic_year')->distinct()->pluck('academic_year');
        $allYears = $existingYears->merge($enrollmentYears)->unique()->sort()->values();

        foreach ($allYears as $year) {
            // Parse "YYYY-YYYY" format
            $parts = explode('-', $year);
            $startYear = $parts[0] ?? date('Y');
            $endYear   = $parts[1] ?? ((int)$startYear + 1);

            DB::table('academic_years')->insert([
                'name'       => $year,
                'start_date' => $startYear . '-09-01',
                'end_date'   => $endYear . '-06-30',
                'is_active'  => false,
                'status'     => 'completed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Mark the most recent year as active
        $latestYear = DB::table('academic_years')->orderBy('name', 'desc')->first();
        if ($latestYear) {
            DB::table('academic_years')->where('id', $latestYear->id)->update([
                'is_active' => true,
                'status'    => 'active',
            ]);
        }

        // 3. Add academic_year_id column to 'classes' (nullable so data migration can happen first)
        Schema::table('classes', function (Blueprint $table) {
            $table->unsignedBigInteger('academic_year_id')->nullable()->after('academic_year');
        });

        // 4. Populate classes.academic_year_id from the string column
        $academicYearMap = DB::table('academic_years')->pluck('id', 'name');
        DB::table('classes')->get()->each(function ($class) use ($academicYearMap) {
            if (isset($academicYearMap[$class->academic_year])) {
                DB::table('classes')->where('id', $class->id)->update([
                    'academic_year_id' => $academicYearMap[$class->academic_year],
                ]);
            }
        });

        // 5. Add academic_year_id to student_enrollments
        Schema::table('student_enrollments', function (Blueprint $table) {
            $table->unsignedBigInteger('academic_year_id')->nullable()->after('academic_year');
        });

        // 6. Populate student_enrollments.academic_year_id from the string column
        DB::table('student_enrollments')->get()->each(function ($enrollment) use ($academicYearMap) {
            if (isset($academicYearMap[$enrollment->academic_year])) {
                DB::table('student_enrollments')->where('id', $enrollment->id)->update([
                    'academic_year_id' => $academicYearMap[$enrollment->academic_year],
                ]);
            }
        });

        // 7. Add academic_year_id to class_subject_teacher
        Schema::table('class_subject_teacher', function (Blueprint $table) {
            $table->unsignedBigInteger('academic_year_id')->nullable()->after('teacher_id');
        });

        // Populate class_subject_teacher.academic_year_id based on the class's academic_year_id
        DB::table('class_subject_teacher')->get()->each(function ($row) {
            $class = DB::table('classes')->where('id', $row->class_id)->first();
            if ($class && $class->academic_year_id) {
                DB::table('class_subject_teacher')->where('id', $row->id)->update([
                    'academic_year_id' => $class->academic_year_id,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('class_subject_teacher', function (Blueprint $table) {
            $table->dropColumn('academic_year_id');
        });

        Schema::table('student_enrollments', function (Blueprint $table) {
            $table->dropColumn('academic_year_id');
        });

        Schema::table('classes', function (Blueprint $table) {
            $table->dropColumn('academic_year_id');
        });

        Schema::dropIfExists('academic_years');
    }
};
