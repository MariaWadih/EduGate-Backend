<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up()
{
    Schema::table('attendance_records', function (Blueprint $table) {
        $table->foreignId('subject_id')->nullable()->after('class_id')->constrained('subjects')->onDelete('cascade');
        $table->foreignId('teacher_id')->nullable()->after('subject_id')->constrained('teachers')->onDelete('cascade');
    });
}

public function down()
{
    Schema::table('attendance_records', function (Blueprint $table) {
        $table->dropForeign(['subject_id']);
        $table->dropForeign(['teacher_id']);
        $table->dropColumn(['subject_id', 'teacher_id']);
    });
}
};
