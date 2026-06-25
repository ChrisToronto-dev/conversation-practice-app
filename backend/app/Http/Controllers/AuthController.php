<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AuthController extends Controller
{
    public function verify(Request $request)
    {
        $apiKey = trim((string) $request->input('api_key', ''));
        $ttsKey = trim((string) $request->input('tts_key', ''));

        // Groq API keys typically start with 'gsk_'
        $groqValid = $apiKey !== '' && str_starts_with($apiKey, 'gsk_');
        $ttsProvided = $ttsKey !== '';
        $ttsResult = $ttsProvided
            ? $this->verifyGoogleTtsKey($ttsKey)
            : ['valid' => false, 'error' => null];

        if ($groqValid) {
            return response()->json([
                'success' => true,
                'groq_valid' => true,
                'tts_valid' => $ttsResult['valid'],
                'tts_provided' => $ttsProvided,
                'tts_error' => $ttsResult['error'],
            ]);
        }

        return response()->json(['success' => false, 'message' => 'Invalid Groq API Key format'], 401);
    }

    private function verifyGoogleTtsKey(string $ttsKey): array
    {
        if (!str_starts_with($ttsKey, 'AIza')) {
            return ['valid' => false, 'error' => 'Google TTS key must start with AIza.'];
        }

        $payload = [
            'input' => ['text' => 'Google TTS check.'],
            'voice' => [
                'languageCode' => 'en-US',
                'name' => 'en-US-Neural2-F',
            ],
            'audioConfig' => [
                'audioEncoding' => 'MP3',
            ],
        ];

        try {
            $response = Http::timeout(12)->post("https://texttospeech.googleapis.com/v1/text:synthesize?key={$ttsKey}", $payload);

            if (!$response->successful()) {
                return [
                    'valid' => false,
                    'error' => 'Google TTS verification failed. Check that Cloud Text-to-Speech API is enabled and the key is unrestricted for this API.',
                ];
            }

            return [
                'valid' => (bool) $response->json('audioContent'),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return ['valid' => false, 'error' => 'Google TTS verification request failed.'];
        }
    }
}
