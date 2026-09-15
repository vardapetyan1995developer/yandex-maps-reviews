<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ParseStatus;
use App\Jobs\ParseOrganizationJob;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Queue::fake();
    }

    #[Test]
    public function it_connects_a_card_and_queues_parsing_instead_of_doing_it_inline(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/organizations', [
                'url' => 'https://yandex.ru/maps/org/yandex/1124715036/',
            ])
            ->assertCreated()
            ->assertJsonPath('data.external_id', '1124715036')
            ->assertJsonPath('data.parse_status', ParseStatus::Queued->value);

        // Parsing must not happen inside an HTTP request: that is tens of
        // seconds of calls to an external source
        Queue::assertPushed(ParseOrganizationJob::class);

        $this->assertDatabaseHas('organizations', [
            'external_id' => '1124715036',
            'user_id' => $this->user->id,
        ]);

        $this->assertDatabaseHas('parse_runs', [
            'organization_id' => $response->json('data.id'),
            'status' => ParseStatus::Queued->value,
        ]);
    }

    #[Test]
    public function it_normalises_the_url_before_storing_it(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/organizations', [
                'url' => '  https://yandex.ru/maps/org/yandex/1124715036/reviews/?ll=37.5%2C55.7&z=17  ',
            ])
            ->assertCreated()
            // Query-string noise must not leak into the stored URL
            ->assertJsonPath('data.url', 'https://yandex.ru/maps/org/yandex/1124715036');
    }

    public static function invalidUrls(): array
    {
        return [
            'different platform' => ['https://2gis.ru/moscow/firm/123'],
            'not a link' => ['просто текст'],
            'map without an organization' => ['https://yandex.ru/maps/213/moscow/'],
        ];
    }

    #[Test]
    #[DataProvider('invalidUrls')]
    public function it_rejects_unsupported_urls_with_a_readable_message(string $url): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/organizations', ['url' => $url])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function reconnecting_the_same_card_updates_it_instead_of_duplicating(): void
    {
        $url = 'https://yandex.ru/maps/org/yandex/1124715036/';

        $first = $this->actingAs($this->user)->postJson('/api/organizations', ['url' => $url]);
        $second = $this->actingAs($this->user)->postJson('/api/organizations', ['url' => $url]);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Organization::count());
    }

    #[Test]
    public function a_user_cannot_see_another_users_card(): void
    {
        $foreign = Organization::factory()->create();

        // 404 rather than 403: a 403 would confirm someone else's record exists
        $this->actingAs($this->user)
            ->getJson("/api/organizations/{$foreign->id}")
            ->assertNotFound();
    }

    #[Test]
    public function it_refuses_to_start_a_second_parse_while_one_is_running(): void
    {
        $organization = Organization::factory()->for($this->user)->create([
            'parse_status' => ParseStatus::Running,
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/organizations/{$organization->id}/refresh")
            ->assertStatus(409);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_allows_a_manual_refresh_of_a_finished_card(): void
    {
        $organization = Organization::factory()->for($this->user)->create([
            'parse_status' => ParseStatus::Success,
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/organizations/{$organization->id}/refresh")
            ->assertStatus(202);

        Queue::assertPushed(ParseOrganizationJob::class);
    }

    #[Test]
    public function it_reports_separate_counters_for_ratings_and_reviews(): void
    {
        $organization = Organization::factory()->for($this->user)->create([
            'ratings_count' => 21229,
            'reviews_count' => 5864,
            'reviews_stored' => 600,
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/organizations/{$organization->id}")
            ->assertOk()
            ->assertJsonPath('data.ratings_count', 21229)
            ->assertJsonPath('data.reviews_count', 5864)
            ->assertJsonPath('data.reviews_stored', 600)
            // The partial flag must be visible to the client, otherwise the
            // interface would pass 600 collected reviews off as all 5864
            ->assertJsonPath('data.is_partial', true);
    }
}
