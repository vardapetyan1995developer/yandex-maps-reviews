<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // The review's identifier on the source is the idempotency key.
            // A repeat parse updates the existing row instead of duplicating it.
            $table->string('external_id', 128);

            $table->string('author_name');
            $table->string('author_avatar', 1024)->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('text')->nullable();
            $table->timestamp('published_at')->nullable();

            // Hash of the meaningful content. Comparing it against the stored
            // value answers "is this the same review or was it edited" without
            // diffing the text line by line.
            $table->char('content_hash', 32);

            // A pair of dates instead of a hard delete: a review absent from
            // the latest run has most likely been hidden or removed on the
            // platform. For a reputation service that is a valuable fact, not
            // noise.
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('disappeared_at')->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'external_id']);

            // Serves the interface's main query: a page of reviews ordered by
            // date. One covering composite index rather than two separate ones.
            $table->index(['organization_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
