<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The running order of an event: a list of time and title.
 *
 * JSON, nullable. An event without a programme is the normal case, and the list
 * is only ever read and written as a whole, so a child table would buy joins and
 * no query anyone runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->json('program')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('program');
        });
    }
};
