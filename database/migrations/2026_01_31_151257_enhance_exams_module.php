<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->text('description')->nullable()->after('title');
            $table->foreignId('teacher_id')->nullable()->constrained('teachers')->onDelete('cascade')->after('subject_id');
            $table->enum('type', ['file', 'mcq'])->default('file')->after('description');
            $table->dateTime('start_time')->nullable()->after('type');
            $table->dateTime('end_time')->nullable()->after('start_time');
            $table->string('file_path')->nullable()->after('end_time');
            $table->string('file_name')->nullable()->after('file_path');
            $table->integer('duration_minutes')->nullable()->after('end_time');
        });

        Schema::create('exam_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->onDelete('cascade');
            $table->text('question_text');
            $table->json('options'); // JSON array of options
            $table->string('correct_option');
            $table->decimal('points', 5, 2)->default(1);
            $table->timestamps();
        });

        Schema::create('exam_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->onDelete('cascade');
            $table->foreignId('student_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['started', 'submitted', 'graded'])->default('started');
            $table->json('mcq_answers')->nullable(); // For MCQ type
            $table->string('file_path')->nullable(); // For file type
            $table->string('file_name')->nullable(); // For file type
            $table->decimal('score', 5, 2)->nullable();
            $table->text('teacher_feedback')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_submissions');
        Schema::dropIfExists('exam_questions');
        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn(['description', 'teacher_id', 'type', 'start_time', 'end_time', 'file_path', 'file_name', 'duration_minutes']);
        });
    }
};
