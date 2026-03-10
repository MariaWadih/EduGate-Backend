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
        Schema::table('students', function (Blueprint $table) {
            $table->string('status')->default('active')->after('class_id'); // active, unenrolled, alumni
            $table->date('enrolled_at')->nullable()->after('status');
        });

        Schema::table('teachers', function (Blueprint $table) {
            $table->string('status')->default('active')->after('user_id'); // active, inactive
            $table->date('joined_at')->nullable()->after('status');
        });

        Schema::table('classes', function (Blueprint $table) {
            $table->string('academic_year')->default('2023-2024')->after('section');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['status', 'enrolled_at']);
        });

        Schema::table('teachers', function (Blueprint $table) {
            $table->dropColumn(['status', 'joined_at']);
        });

        Schema::table('classes', function (Blueprint $table) {
            $table->dropColumn('academic_year');
        });
    }
};
