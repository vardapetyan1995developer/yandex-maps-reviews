<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshots of an organization's aggregates — one per successful parse.
     *
     * Answers "what changed between parses": the rating and counters are frozen
     * at the moment of the run, so the trend falls out of comparing adjacent
     * rows. They cannot live on the card itself, which only ever holds the
     * current state.
     */
    public function up(): void
    {
        Schema::create('organization_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parse_run_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name')->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            $table->unsignedInteger('ratings_count')->default(0);
            $table->unsignedInteger('reviews_count')->default(0);
            $table->unsignedInteger('reviews_stored')->default(0);

            // Useful during incident review: the snapshot in its original
            // form, even if the column set changes over time
            $table->json('payload')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_snapshots');
    }
};
