<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One answer per account and event.
 *
 * `user_id` is the Statamic user id, a string, and deliberately has no foreign
 * key: users live in the Stache or in a host table this addon knows nothing
 * about. Removing the answers of a deleted account is `Rsvps::eraseFor()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_rsvps', function (Blueprint $table) {
            $table->id();
            // Copied from the event, like on occurrences: every index leads with it.
            $table->unsignedBigInteger('brand_id')->index();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('user_id', 64);
            // going | interested | not_going — see Enums\RsvpStatus.
            $table->string('status', 16);
            $table->timestamps();

            $table->unique(['brand_id', 'event_id', 'user_id'], 'evt_rsvp_event_user_unique');
            $table->index(['brand_id', 'user_id'], 'evt_rsvp_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_rsvps');
    }
};
