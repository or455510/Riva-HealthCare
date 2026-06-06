<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiAssistantController extends Controller
{
    private const PERSONAS = [
        'primary-care' => [
            'name' => 'Primary Care Robot',
            'prompt' => 'You are Riva Doctor, a friendly Egyptian primary care medical assistant. Speak clearly, empathetically, and in simple language. Provide general health education, help the user organize symptoms, and suggest when to contact a clinician. Never diagnose, prescribe, or replace emergency care.',
        ],
        'cardiology' => [
            'name' => 'Cardio Robot',
            'prompt' => 'You are Riva Doctor, an Egyptian cardiology-focused assistant. Help users understand heart-health tracking, blood pressure habits, warning signs, and questions to prepare for a clinician. Never diagnose, prescribe, or replace emergency care.',
        ],
        'dermatology' => [
            'name' => 'Skin Care Robot',
            'prompt' => 'You are Riva Doctor, an Egyptian dermatology-focused assistant. Help users organize skin symptoms, rash notes, photo tracking, and clinician follow-up questions. Never diagnose, prescribe, or replace emergency care.',
        ],
        'pediatrics' => [
            'name' => 'Pediatric Robot',
            'prompt' => 'You are Riva Doctor, an Egyptian pediatrics-focused assistant. Help families organize child symptoms, routines, and non-emergency questions for clinicians. Never diagnose, prescribe, or replace emergency care.',
        ],
    ];

    public function message(Request $request)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'personaId' => ['required', 'string', 'max:80'],
            'history' => ['nullable', 'array', 'max:12'],
            'history.*.role' => ['required_with:history', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string', 'max:4000'],
        ]);

        $apiKey = config('services.gemini.key');
        if (!$apiKey) {
            Log::error('Gemini API key missing for AI assistant endpoint', [
                'env_name' => 'GEMINI_API_KEY',
                'model' => config('services.gemini.model', 'gemini-2.5-flash'),
            ]);

            return $this->error('Gemini API key is missing on the backend. Set GEMINI_API_KEY in Laravel .env.', 503);
        }

        $persona = self::PERSONAS[$data['personaId']] ?? self::PERSONAS['primary-care'];
        $model = config('services.gemini.model', 'gemini-2.5-flash');
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
        $httpOptions = ['verify' => (bool) config('services.gemini.verify_ssl', true)];

        $keyCheck = $this->checkGeminiKey($apiKey, $httpOptions);
        if (!$keyCheck['valid']) {
            return $this->error($keyCheck['message'], 503, $keyCheck['errors']);
        }

        $contents = $this->buildGeminiContents($data['history'] ?? [], $data['message']);

        try {
            $response = Http::timeout(12)
                ->connectTimeout(5)
                ->withOptions($httpOptions)
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'x-goog-api-key' => $apiKey,
                ])
                ->post($endpoint, [
                    'system_instruction' => [
                        'parts' => [[
                            'text' => $persona['prompt'] . "\n\nSafety rule: Riva AI Assistant provides general guidance and platform support only. It does not provide diagnosis, treatment decisions, or emergency medical advice.",
                        ]],
                    ],
                    'contents' => $contents,
                    'generationConfig' => [
                        'temperature' => 0.7,
                        'maxOutputTokens' => 1024,
                    ],
                ]);
        } catch (Throwable $exception) {
            Log::warning('Gemini agent connection failed', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
                'model' => $model,
                'persona' => $persona['name'],
                'key_fingerprint' => $this->keyFingerprint($apiKey),
            ]);

            return $this->error('Unable to reach the Gemini agent from Laravel.', 502);
        }

        if ($response->failed()) {
            Log::warning('Gemini agent request failed', [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
                'reason' => data_get($response->json(), 'error.status'),
                'gemini_message' => data_get($response->json(), 'error.message'),
                'model' => $model,
                'persona' => $persona['name'],
                'key_fingerprint' => $this->keyFingerprint($apiKey),
            ]);

            return $this->error('Gemini agent request failed.', 502, $response->json());
        }

        $reply = data_get($response->json(), 'candidates.0.content.parts.0.text');
        if (!$reply) {
            return $this->error('Gemini agent returned an empty response.', 502, $response->json());
        }

        return $this->success([
            'reply' => $reply,
            'provider' => 'gemini',
            'model' => $model,
            'persona' => $persona['name'],
            'endpoint' => $endpoint,
        ]);
    }

    private function buildGeminiContents(array $history, string $message): array
    {
        $contents = [];
        $trimmedMessage = trim($message);
        $recentHistory = array_slice($history, -10);

        foreach ($recentHistory as $item) {
            $text = trim((string) ($item['content'] ?? ''));
            if ($text === '') {
                continue;
            }

            $role = ($item['role'] ?? null) === 'assistant' ? 'model' : 'user';

            if ($contents === [] && $role !== 'user') {
                continue;
            }

            $lastIndex = count($contents) - 1;
            if ($lastIndex >= 0 && $contents[$lastIndex]['role'] === $role) {
                $contents[$lastIndex]['parts'][0]['text'] .= "\n\n" . $text;
                continue;
            }

            $contents[] = [
                'role' => $role,
                'parts' => [['text' => $text]],
            ];
        }

        $last = $contents[count($contents) - 1] ?? null;
        $lastText = trim((string) data_get($last, 'parts.0.text', ''));
        if (!$last || $last['role'] !== 'user' || $lastText !== $trimmedMessage) {
            $contents[] = [
                'role' => 'user',
                'parts' => [['text' => $trimmedMessage]],
            ];
        }

        return $contents;
    }

    private function checkGeminiKey(string $apiKey, array $httpOptions): array
    {
        try {
            $response = Http::timeout(8)
                ->connectTimeout(4)
                ->withOptions($httpOptions)
                ->acceptJson()
                ->withHeaders([
                    'x-goog-api-key' => $apiKey,
                ])
                ->get('https://generativelanguage.googleapis.com/v1beta/models');
        } catch (Throwable $exception) {
            Log::warning('Gemini key preflight failed', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
                'key_fingerprint' => $this->keyFingerprint($apiKey),
            ]);

            return [
                'valid' => false,
                'message' => 'Unable to verify the Gemini API key from Laravel.',
                'errors' => null,
            ];
        }

        if ($response->successful()) {
            return [
                'valid' => true,
                'message' => null,
                'errors' => null,
            ];
        }

        $message = data_get($response->json(), 'error.message', 'Gemini API key is invalid.');

        Log::warning('Gemini key preflight rejected the configured key', [
            'status' => $response->status(),
            'message' => $message,
            'reason' => data_get($response->json(), 'error.status'),
            'body' => $response->json() ?? $response->body(),
            'key_fingerprint' => $this->keyFingerprint($apiKey),
        ]);

        return [
            'valid' => false,
            'message' => 'Gemini API key is invalid or expired. Renew GEMINI_API_KEY in Laravel .env.',
            'errors' => $response->json(),
        ];
    }

    private function keyFingerprint(string $apiKey): string
    {
        return substr($apiKey, 0, 6) . '...' . substr($apiKey, -4) . ' len=' . strlen($apiKey);
    }
}
