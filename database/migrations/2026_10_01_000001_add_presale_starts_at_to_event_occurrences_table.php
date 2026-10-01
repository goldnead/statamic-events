<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The day ticket sales begin.
 *
 * A date that is on sale has two instants that matter: when the room opens and
 * when the box office does. The first is `starts_at`; the second is this column,
 * and it is what a presale mailing is timed against — "Der Vorverkauf hat
 * gestartet" is only true from this moment on.
 *
 * Nullable for the same reason `ends_at` is: a date without a presale date is a
 * normal case (free entry, tickets at the door), not an error, and 1970 or
 * `now()` as a placeholder would make a mailing fire on a day nobody chose.
 *
 * UTC like `starts_at`, stored as a datetime and converted by the same cast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_occurrences', function (Blueprint $table) {
            $table->datetime('presale_starts_at')->nullable()->after('is_free');
        });
    }

    public function down(): void
    {
        Schema::table('event_occurrences', function (Blueprint $table) {
            $table->dropColumn('presale_starts_at');
        });
    }
};
