<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Edit history for reviews: before and after.
     *
     * A row appears only when an existing review's content_hash diverges from
     * the new one — that is, the author edited the text or the rating. For a
     * reputation service this is a significant event: a review may have turned
     * from positive to negative after someone had already replied to it.
     */
    public function up(): void
    {
        Schema::create('review_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parse_run_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedTinyInteger('old_rating')->nullable();
            $table->unsignedTinyInteger('new_rating')->nullable();
            $table->text('old_text')->nullable();
            $table->text('new_text')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['review_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_revisions');
    }
};
