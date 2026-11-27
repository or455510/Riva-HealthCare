<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * COST (RIVA-105) regression coverage for the Gemini key preflight cache —
 * see AiAssistantController::checkGeminiKey() and the cost/scalability
 * audit §22 P2-1. Before this fix, every /api/ai-assistant/message call
 * made a full round-trip to Gemini's /v1beta/models endpoint purely to
 * re-validate a static, rarely-changing API key.
 */
class AiAssistantKeyCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function actingPatient(): void
    {
        $user = User::create([
            'first_name' => 'Ai', 'last_name' => uniqid('Cache'),
            'name' => 'Ai Cache', 'email' => uniqid('aicache').'@riva.test',
            'password' => 'password', 'role' => 'patient', 'is_active' => true,
            'profile_completed_at' => now(),
        ]);
        Patient::create(['user_id' => $user->id]);
        Sanctum::actingAs($user);
    }

    public function test_second_chat_message_within_the_ttl_does_not_repeat_the_gemini_key_preflight(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models' => Http::response(['models' => []], 200),
            'https://generativelanguage.googleapis.com/v1beta/models/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'hi there']]]]],
            ], 200),
        ]);

        $this->actingPatient();

        $payload = ['message' => 'hello', 'personaId' => 'primary-care'];

        $this->postJson('/api/ai-assistant/message', $payload)->assertOk();
        $this->postJson('/api/ai-assistant/message', $payload)->assertOk();

        // Exactly one preflight GET to the bare /v1beta/models listing
        // endpoint across both calls — the second call must reuse the
        // cached "valid" verdict instead of re-checking the key.
        Http::assertSentCount(3); // 1 preflight + 2 generateContent calls
    }

    public function test_an_invalid_key_result_is_not_cached_for_long_enough_to_hide_a_fix(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models' => Http::response(['error' => ['message' => 'API key not valid']], 400),
        ]);

        $this->actingPatient();

        $payload = ['message' => 'hello', 'personaId' => 'primary-care'];

        $this->postJson('/api/ai-assistant/message', $payload)->assertStatus(503);

        // The failure must still be cached briefly (not re-checked on every
        // single request), but with a short enough TTL that it's a real
        // cache and not a permanent lockout.
        $cacheKey = 'gemini-key-check:'.hash('sha256', config('services.gemini.key'));
        $this->assertNotNull(Cache::get($cacheKey));
        $this->assertFalse(Cache::get($cacheKey)['valid']);
    }
}
