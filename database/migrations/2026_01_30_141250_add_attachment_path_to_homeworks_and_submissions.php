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
        Schema::table('homeworks', function (Blueprint $table) {
            $table->string('file_path')->nullable()->after('description');
            $table->string('file_name')->nullable()->after('file_path');
        });

        Schema::table('homework_submissions', function (Blueprint $table) {
            $table->string('file_path')->nullable()->after('content');
            $table->string('file_name')->nullable()->after('file_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('homeworks', function (Blueprint $table) {
            $table->dropColumn(['file_path', 'file_name']);
        });

        Schema::table('homework_submissions', function (Blueprint $table) {
            $table->dropColumn(['file_path', 'file_name']);
        });
    }
};
