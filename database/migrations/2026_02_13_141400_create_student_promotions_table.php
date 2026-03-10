<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
            $table->foreignId('from_class_id')->constrained('classes')->onDelete('cascade');
            $table->foreignId('to_class_id')->nullable()->constrained('classes')->onDelete('set null');
            $table->string('from_academic_year'); // e.g., "2023-2024"
            $table->string('to_academic_year'); // e.g., "2024-2025"
            $table->enum('status', ['promoted', 'failed', 'repeated'])->default('promoted');
            $table->text('remarks')->nullable(); // Reason for failure, special notes
            $table->date('promotion_date');
            $table->timestamps();
            
            $table->index(['student_id', 'from_academic_year']);
        });

        // Add academic_year to students table to track current year
        Schema::table('students', function (Blueprint $table) {
            $table->string('current_academic_year')->nullable()->after('class_id');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('current_academic_year');
        });
        
        Schema::dropIfExists('student_promotions');
    }
};
