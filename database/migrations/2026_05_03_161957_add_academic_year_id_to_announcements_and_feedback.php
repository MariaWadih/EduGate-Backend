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
    Schema::table('announcements', function (Blueprint $table) {
        $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
    });

    Schema::table('feedback', function (Blueprint $table) {
        $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
    });
}

public function down(): void
{
    Schema::table('announcements', function (Blueprint $table) {
        $table->dropForeignIdFor(\App\Models\AcademicYear::class);
        $table->dropColumn('academic_year_id');
    });

    Schema::table('feedback', function (Blueprint $table) {
        $table->dropForeignIdFor(\App\Models\AcademicYear::class);
        $table->dropColumn('academic_year_id');
    });
}
};
