<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The id the browser sees, and the one that lands in an export file.
            // Unique per account rather than globally, so two people can import
            // the same export without one of them colliding with the other.
            $table->string('public_id', 64);

            $table->timestamp('entry_date');
            $table->timestamps();

            $table->unique(['user_id', 'public_id']);
            $table->index(['user_id', 'entry_date']);
        });

        // Normalised rather than a JSON blob, so the lines of an entry stay
        // queryable (search, streaks, word counts) later on.
        Schema::create('entry_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->text('body');

            $table->unique(['entry_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_items');
        Schema::dropIfExists('entries');
    }
};
