<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Postal code and coordinates on the occurrence.
 *
 * `venue_city` answers "where is it" for a reader. It does not answer "is it
 * within 50 km of me", which is the question a regional invitation asks before
 * it is sent, and the one a map asks before it draws a pin. A city name cannot
 * be compared by distance and does not survive a spelling; a postal code and a
 * latitude/longitude pair can and do.
 *
 * The postal code is a string, not an integer. Austria writes `A-1070`,
 * the Netherlands `1011 AB`, the UK `SW1A 1AA`. Every five-digit assumption
 * breaks on the first concert across the border.
 *
 * Coordinates are decimal(10,7): roughly a centimetre of precision, far more
 * than a venue needs, and exact enough that a radius query never rounds a
 * venue across a border it was near.
 *
 * Ticketing rides along because it is the same question about the same date:
 * where do I go, and how do I get in. A ticket link belongs to the occurrence,
 * not the event — a tour sells each night separately, and an event-level link
 * would send everyone to the wrong night. `is_free` exists so that a missing
 * link reads as "free" rather than as "we forgot the link".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_occurrences', function (Blueprint $table) {
            $table->string('venue_postal_code', 16)->nullable()->after('venue_city');
            $table->decimal('venue_latitude', 10, 7)->nullable()->after('venue_country');
            $table->decimal('venue_longitude', 10, 7)->nullable()->after('venue_latitude');
            $table->string('tickets_url', 512)->nullable()->after('online_url');
            $table->boolean('is_free')->default(false)->after('tickets_url');

            // A radius query filters by brand first, then narrows by a
            // bounding box on the two coordinates before it measures anything.
            $table->index(['brand_id', 'venue_latitude', 'venue_longitude'], 'evtocc_brand_geo_idx');
        });
    }

    public function down(): void
    {
        Schema::table('event_occurrences', function (Blueprint $table) {
            $table->dropIndex('evtocc_brand_geo_idx');
            $table->dropColumn(['venue_postal_code', 'venue_latitude', 'venue_longitude', 'tickets_url', 'is_free']);
        });
    }
};
