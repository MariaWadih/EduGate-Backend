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
        Schema::table('student_promotions', function (Blueprint $table) {
            // Change status to string to allow more flexible values like 'graduated'
            $table->string('status')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_promotions', function (Blueprint $table) {
            $table->enum('status', ['promoted', 'failed', 'repeated'])->default('promoted')->change();
        });
    }
};
