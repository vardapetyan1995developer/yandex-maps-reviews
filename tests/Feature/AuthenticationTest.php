<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create([
            'email' => 'demo@example.com',
            'password' => 'password',
        ]);
    }

    #[Test]
    public function a_seeded_user_can_log_in(): void
    {
        $this->user();

        $this->postJson('/api/login', [
            'email' => 'demo@example.com',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('data.email', 'demo@example.com');

        $this->assertAuthenticated();
    }

    #[Test]
    public function it_rejects_a_wrong_password_without_revealing_which_field_is_wrong(): void
    {
        $this->user();

        $response = $this->postJson('/api/login', [
            'email' => 'demo@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);

        // The message must not reveal whether the account exists
        $this->assertSame('Неверный email или пароль.', $response->json('errors.email.0'));
        $this->assertGuest();
    }

    #[Test]
    public function it_throttles_repeated_failed_attempts(): void
    {
        $this->user();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/login', [
                'email' => 'demo@example.com',
                'password' => 'wrong-password',
            ]);
        }

        $this->postJson('/api/login', [
            'email' => 'demo@example.com',
            'password' => 'password',
        ])->assertStatus(422)
            ->assertJsonPath('errors.email.0', fn (string $message): bool => str_contains($message, 'Слишком много попыток'));
    }

    #[Test]
    public function protected_endpoints_reject_guests(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
        $this->getJson('/api/organizations')->assertUnauthorized();
    }

    #[Test]
    public function a_user_can_log_out(): void
    {
        $this->actingAs($this->user())
            ->postJson('/api/logout')
            ->assertOk();

        // The web guard is what is asserted on: it owns the session that
        // logout clears. Within the same process the sanctum guard keeps the
        // already-resolved user in memory — a quirk of the test environment,
        // not the state of the session.
        $this->assertGuest('web');
    }
}
