<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\SensorReading;
use Illuminate\Support\Facades\Log;

class AiController extends Controller
{
    /**
     * Helper to clean AI response and decode JSON robustly.
     */
    private function parseAiResponse($response)
    {
        try {
            if (!$response->successful()) {
                Log::error('AI API Call Failed', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return ['error_detail' => 'API returned status ' . $response->status(), 'body' => $response->body()];
            }

            $content = $response->json('choices.0.message.content');

            if (!$content) {
                return ['error_detail' => 'Empty content from AI'];
            }

            // Remove markdown code blocks if the LLM included them
            $cleanJson = preg_replace('/^```json|```$/m', '', $content);
            $decoded = json_decode(trim($cleanJson), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'error_detail' => 'JSON Decode Failed: ' . json_last_error_msg(),
                    'raw_content' => $content
                ];
            }

            return $decoded;
        } catch (\Exception $e) {
            return ['error_detail' => 'Exception in parser: ' . $e->getMessage()];
        }
    }

    public function getInsights()
    {
        try {
            $latestReading = SensorReading::latest()->first();

            if (!$latestReading) {
                return response()->json(['error' => 'No sensor data available in database'], 404);
            }

            // Check config FIRST, then fallback to ENV directly (common fix for Render/Heroku)
            $token = config('services.github.token') ?? env('GITHUB_TOKEN');

            if (empty($token)) {
                return response()->json([
                    'error' => 'Configuration Error',
                    'message' => 'GITHUB_TOKEN is missing. Check Render Environment variables.'
                ], 500);
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ])->timeout(45)->post('https://models.github.ai/inference/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a smart room assistant. Return ONLY a valid JSON object. No markdown. Keys: "issue", "suggestion".'
                    ],
                    [
                        'role' => 'user',
                        'content' => 'Data: ' . json_encode([
                            'temp' => $latestReading->temperature,
                            'hum' => $latestReading->humidity,
                        ])
                    ]
                ]
            ]);

            $aiData = $this->parseAiResponse($response);

            // If the parser returned an array with error_detail, it failed
            if (isset($aiData['error_detail'])) {
                return response()->json([
                    'error' => 'AI Processing Failed',
                    'debug' => $aiData
                ], 500);
            }

            return response()->json($aiData, 200);
        } catch (\Exception $e) {
            // This prevents the White Screen by catching the fatal error
            return response()->json([
                'error' => 'Controller Crash',
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 500);
        }
    }
}
