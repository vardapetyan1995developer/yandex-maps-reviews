<?php

declare(strict_types=1);

use App\Enums\ParseStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Source plus the card's identifier on it. The pair is unique and
            // is what makes a repeat parse idempotent — the URL can change
            // (the slug follows the business name), the id cannot.
            $table->string('source', 32)->default('yandex_maps');
            $table->string('external_id', 64);
            $table->string('slug')->nullable();
            $table->string('url', 2048);

            $table->string('name')->nullable();
            $table->string('address')->nullable();
            $table->json('categories')->nullable();

            // The three figures the interface needs. ratings_count and
            // reviews_count are stored separately on purpose: a star rating
            // without text is left far more often than a written review, and
            // the two must not be conflated.
            $table->decimal('rating', 3, 2)->nullable();
            $table->unsignedInteger('ratings_count')->default(0);
            $table->unsignedInteger('reviews_count')->default(0);

            // How many reviews we actually hold. Divergence from reviews_count
            // is normal (the source limits output depth) but must be visible
            // explicitly rather than passing for the complete picture.
            $table->unsignedInteger('reviews_stored')->default(0);

            $table->string('parse_status', 16)->default(ParseStatus::Pending->value);
            $table->timestamp('last_parsed_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_id']);
            $table->index(['user_id', 'parse_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
