<?php

declare(strict_types=1);

use App\Enums\ParseStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Log of parse runs.
     *
     * A separate table rather than a couple of columns on organizations, for
     * three reasons: it backs the progress indicator, it keeps a history of
     * attempts for incident review, and it gives diagnostics from a broken
     * parser somewhere to live.
     */
    public function up(): void
    {
        Schema::create('parse_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('status', 16)->default(ParseStatus::Queued->value);
            $table->string('strategy', 32)->nullable();

            $table->unsignedInteger('pages_fetched')->default(0);
            $table->unsignedInteger('reviews_found')->default(0);
            $table->unsignedInteger('reviews_created')->default(0);
            $table->unsignedInteger('reviews_updated')->default(0);

            // Progress is written straight here; the frontend polls it while
            // the job runs in the background
            $table->unsignedInteger('progress_total')->nullable();
            $table->unsignedTinyInteger('progress_percent')->default(0);

            $table->boolean('truncated')->default(false);
            $table->string('truncation_reason', 64)->nullable();

            $table->string('error_code', 32)->nullable();
            $table->text('error_message')->nullable();

            // A snapshot of what the source actually returned at the moment of
            // failure. Without it, debugging "the parser broke in production"
            // is guesswork.
            $table->json('error_context')->nullable();

            $table->unsignedTinyInteger('attempt')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parse_runs');
    }
};
