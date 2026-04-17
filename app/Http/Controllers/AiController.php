<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SensorReading;
use App\Support\SensorReadingSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiController extends Controller
{
    private const INSIGHTS_CACHE_SECONDS = 300;
    private const PREDICTION_CACHE_SECONDS = 300;
    private const CHAT_CACHE_SECONDS = 90;
    private const BACKUP_CACHE_SECONDS = 86400;

    /**
     * Helper to clean AI response and decode JSON robustly.
     */
    private function parseAiResponse($response)
    {
        try {
            if (!$response->successful()) {
                Log::error('AI API Call Failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'error_detail' => 'API returned status ' . $response->status(),
                    'body' => $response->body(),
                ];
            }

            $content = $this->extractGeminiResponseContent($response->json('candidates.0.content.parts'));

            if (!$content) {
                return ['error_detail' => 'Empty content from AI'];
            }

            // Strip markdown fences and salvage the first JSON object if extra text appears.
            $cleanJson = preg_replace('/^```(?:json)?|```$/m', '', $content);
            if (preg_match('/\{.*\}/s', $cleanJson, $matches) === 1) {
                $cleanJson = $matches[0];
            }

            $decoded = json_decode(trim($cleanJson), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'error_detail' => 'JSON Decode Failed: ' . json_last_error_msg(),
                    'raw_content' => $content,
                ];
            }

            return $decoded;
        } catch (\Throwable $e) {
            return ['error_detail' => 'Exception in parser: ' . $e->getMessage()];
        }
    }

    private function extractGeminiResponseContent($parts): ?string
    {
        if (!is_array($parts)) {
            return null;
        }

        $texts = [];

        foreach ($parts as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $texts[] = $part['text'];
            }
        }

        return empty($texts) ? null : implode('', $texts);
    }

    private function geminiApiKey(): ?string
    {
        $token = config('services.gemini.api_key') ?? env('GEMINI_API_KEY');

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }

    private function geminiModel(): string
    {
        $model = config('services.gemini.model') ?? env('GEMINI_MODEL');

        return is_string($model) && trim($model) !== '' ? trim($model) : 'gemini-2.5-flash-lite';
    }

    private function geminiVerifyOption(): bool|string
    {
        $verifySsl = config('services.gemini.verify_ssl');
        if ($verifySsl === false || $verifySsl === 'false' || $verifySsl === '0' || $verifySsl === 0) {
            return false;
        }

        $caBundle = config('services.gemini.ca_bundle') ?? env('GEMINI_CA_BUNDLE');

        if (is_string($caBundle) && trim($caBundle) !== '' && is_file($caBundle)) {
            return $caBundle;
        }

        return true;
    }

    private function askModel(array $messages): array
    {
        $token = $this->geminiApiKey();

        if (!$token) {
            return ['error_detail' => 'Missing GEMINI_API_KEY'];
        }

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->withOptions([
                'verify' => $this->geminiVerifyOption(),
            ])->timeout(45)->post(
                'https://generativelanguage.googleapis.com/v1beta/models/' . $this->geminiModel() . ':generateContent?key=' . urlencode($token),
                [
                    'contents' => $this->buildGeminiContents($messages),
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                    ],
                ]
            );

            if (!$response->successful()) {
                Log::error('Gemini API Call Failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'error_detail' => 'API returned status ' . $response->status(),
                    'body' => $response->body(),
                ];
            }

            $finishReason = $response->json('candidates.0.finishReason');
            if (is_string($finishReason) && strtoupper($finishReason) !== 'STOP') {
                return [
                    'error_detail' => 'Gemini finished with reason ' . $finishReason,
                    'body' => $response->body(),
                ];
            }

            return $this->parseAiResponse($response);
        } catch (\Throwable $e) {
            Log::error('AI Request Exception', [
                'message' => $e->getMessage(),
            ]);

            return ['error_detail' => 'Request exception: ' . $e->getMessage()];
        }
    }

    private function buildGeminiContents(array $messages): array
    {
        return array_map(function (array $message) {
            $role = ($message['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
            $text = is_string($message['content'] ?? null) ? $message['content'] : json_encode($message['content']);

            return [
                'role' => $role,
                'parts' => [
                    [
                        'text' => $text,
                    ],
                ],
            ];
        }, $messages);
    }

    private function buildInsightFallback(SensorReading $reading, ?string $warning = null): array
    {
        $issue = 'Conditions are stable right now.';
        $suggestion = 'Keep the room as-is and continue monitoring for changes.';

        if ($reading->temperature >= 27) {
            $issue = 'The room is warmer than comfortable.';
            $suggestion = 'Lower the heat or increase airflow with a fan or open window.';
        } elseif ($reading->temperature <= 18) {
            $issue = 'The room is cooler than comfortable.';
            $suggestion = 'Increase heating slightly or reduce drafts to keep the room comfortable.';
        } elseif ($reading->humidity >= 60) {
            $issue = 'Humidity is running high.';
            $suggestion = 'Improve ventilation or use a dehumidifier to reduce moisture buildup.';
        } elseif ($reading->humidity <= 30) {
            $issue = 'Air humidity is low.';
            $suggestion = 'Add moisture with a humidifier or place water near a heat source.';
        }

        return array_filter([
            'issue' => $issue,
            'suggestion' => $suggestion,
            'source' => 'fallback',
            'warning' => $warning,
        ], fn($value) => $value !== null);
    }

    private function buildPredictionFallback($dataHistory, ?string $warning = null): array
    {
        $latest = $dataHistory->last();
        $first = $dataHistory->first();

        if (!$latest || !$first) {
            return array_filter([
                'trend' => 'Not enough history to estimate a trend yet.',
                'estimated_temp_in_1_hour' => 0,
                'advice' => 'Collect more readings before using the forecast.',
                'source' => 'fallback',
                'warning' => $warning,
            ], fn($value) => $value !== null);
        }

        $minutes = max(15, max(1, ($dataHistory->count() - 1) * 5));
        if ($latest->created_at && $first->created_at) {
            $minutes = max(1, $latest->created_at->diffInMinutes($first->created_at));
        }

        $delta = (float) $latest->temperature - (float) $first->temperature;
        $projectedDelta = ($delta / $minutes) * 60;
        $estimatedTemp = round((float) $latest->temperature + $projectedDelta, 1);

        $trend = 'Temperature is staying mostly steady.';
        if ($projectedDelta >= 1) {
            $trend = 'Temperature is trending upward.';
        } elseif ($projectedDelta <= -1) {
            $trend = 'Temperature is trending downward.';
        }

        $advice = 'No immediate action is needed if the room still feels comfortable.';
        if ($estimatedTemp >= 27) {
            $advice = 'Plan to cool the room soon if the warming trend continues.';
        } elseif ($estimatedTemp <= 18) {
            $advice = 'Prepare to add heat if the room keeps cooling down.';
        }

        return array_filter([
            'trend' => $trend,
            'estimated_temp_in_1_hour' => $estimatedTemp,
            'advice' => $advice,
            'source' => 'fallback',
            'warning' => $warning,
        ], fn($value) => $value !== null);
    }

    private function buildChatFallback(string $message, ?SensorReading $latestReading, ?string $warning = null): array
    {
        if (!$latestReading) {
            return array_filter([
                'reply' => 'Sensor data is not available right now, so I cannot answer accurately yet.',
                'source' => 'fallback',
                'warning' => $warning,
            ], fn($value) => $value !== null);
        }

        $message = strtolower($message);
        $temperature = (float) $latestReading->temperature;
        $humidity = (float) $latestReading->humidity;
        $pressure = (float) $latestReading->pressure;

        if (str_contains($message, 'comfort') || str_contains($message, 'comfortable')) {
            $comfortable = $temperature >= 20 && $temperature <= 25 && $humidity >= 35 && $humidity <= 60;
            $reply = $comfortable
                ? "The room looks comfortable right now at {$temperature} C and {$humidity}% humidity."
                : "The room may feel slightly off right now at {$temperature} C and {$humidity}% humidity.";

            return array_filter([
                'reply' => $reply,
                'source' => 'fallback',
                'warning' => $warning,
            ], fn($value) => $value !== null);
        }

        if (str_contains($message, 'humid')) {
            return array_filter([
                'reply' => "Humidity is currently {$humidity}%. Around 40% to 60% is usually the comfortable range indoors.",
                'source' => 'fallback',
                'warning' => $warning,
            ], fn($value) => $value !== null);
        }

        if (str_contains($message, 'pressure')) {
            return array_filter([
                'reply' => "Pressure is currently {$pressure} hPa. That is mainly useful as a trend signal rather than a direct comfort metric.",
                'source' => 'fallback',
                'warning' => $warning,
            ], fn($value) => $value !== null);
        }

        return array_filter([
            'reply' => "Current room conditions are {$temperature} C, {$humidity}% humidity, and {$pressure} hPa.",
            'source' => 'fallback',
            'warning' => $warning,
        ], fn($value) => $value !== null);
    }

    private function mergeWithFallback(array $payload, array $fallback, string $source = 'ai'): array
    {
        $merged = $fallback;

        foreach ($fallback as $key => $value) {
            if (!array_key_exists($key, $payload) || $payload[$key] === null || $payload[$key] === '') {
                continue;
            }

            $merged[$key] = is_numeric($value) ? (float) $payload[$key] : $payload[$key];
        }

        $merged['source'] = $source;
        unset($merged['warning']);

        return $merged;
    }

    private function cacheKeys(string $prefix, array $context): array
    {
        $hash = sha1(json_encode($context));

        return [
            'hot' => "ai:$prefix:$hash:hot",
            'backup' => "ai:$prefix:$hash:backup",
        ];
    }

    private function resolveCachedResponse(
        string $prefix,
        array $context,
        int $ttlSeconds,
        callable $resolver
    ): array {
        $keys = $this->cacheKeys($prefix, $context);
        $cached = Cache::get($keys['hot']);

        if (is_array($cached)) {
            return $cached;
        }

        $resolved = $resolver();

        if (!isset($resolved['error_detail'])) {
            Cache::put($keys['hot'], $resolved, now()->addSeconds($ttlSeconds));
            Cache::put($keys['backup'], $resolved, now()->addSeconds(self::BACKUP_CACHE_SECONDS));

            return $resolved;
        }

        $backup = Cache::get($keys['backup']);
        if (is_array($backup)) {
            $backup['source'] = 'cached';
            $backup['warning'] = $resolved['error_detail'];

            return $backup;
        }

        return $resolved;
    }

    public function getInsights()
    {
        try {
            $latestReading = app(SensorReadingSource::class)->latest();

            if (!$latestReading) {
                return response()->json(['error' => 'No sensor data available in database'], 404);
            }

            $fallback = $this->buildInsightFallback($latestReading);
            $responseData = $this->resolveCachedResponse(
                'insights',
                [
                    'id' => $latestReading->id,
                    'temperature' => $latestReading->temperature,
                    'humidity' => $latestReading->humidity,
                ],
                self::INSIGHTS_CACHE_SECONDS,
                function () use ($latestReading, $fallback) {
                    $aiData = $this->askModel([
                        [
                            'role' => 'system',
                            'content' => 'You are a smart room assistant. Return ONLY a valid JSON object. No markdown. Keys: "issue", "suggestion".',
                        ],
                        [
                            'role' => 'user',
                            'content' => 'Data: ' . json_encode([
                                'temp' => $latestReading->temperature,
                                'hum' => $latestReading->humidity,
                            ]),
                        ],
                    ]);

                    if (isset($aiData['error_detail'])) {
                        return $aiData;
                    }

                    return $this->mergeWithFallback($aiData, $fallback);
                }
            );

            if (isset($responseData['error_detail'])) {
                Log::warning('Falling back to local insights response', $responseData);

                return response()->json(
                    $this->buildInsightFallback($latestReading, $responseData['error_detail']),
                    200
                );
            }

            return response()->json($responseData, 200);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Controller Crash',
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    /**
     * Predicts future conditions based on the last 20 sensor readings.
     */
    public function getPrediction()
    {
        try {
            $dataHistory = app(SensorReadingSource::class)->recent(20);

            if ($dataHistory->isEmpty()) {
                return response()->json(['error' => 'Not enough data'], 404);
            }

            $formattedData = $dataHistory->map(fn($r) => [
                'temp' => $r->temperature,
                'hum' => $r->humidity,
                'pres' => $r->pressure,
                'time' => $r->created_at ? $r->created_at->format('H:i') : 'unknown',
            ]);

            $fallback = $this->buildPredictionFallback($dataHistory);
            $responseData = $this->resolveCachedResponse(
                'prediction',
                [
                    'count' => $dataHistory->count(),
                    'latest_id' => $dataHistory->last()?->id,
                    'first_id' => $dataHistory->first()?->id,
                ],
                self::PREDICTION_CACHE_SECONDS,
                function () use ($formattedData, $fallback) {
                    $aiData = $this->askModel([
                        [
                            'role' => 'system',
                            'content' => 'You are a smart room predictive AI. Analyze the trajectory of the temperature, humidity, and pressure. Return a JSON object with keys: "trend" (string), "estimated_temp_in_1_hour" (number), and "advice" (string).',
                        ],
                        [
                            'role' => 'user',
                            'content' => 'Historical data: ' . json_encode($formattedData),
                        ],
                    ]);

                    if (isset($aiData['error_detail'])) {
                        return $aiData;
                    }

                    return $this->mergeWithFallback($aiData, $fallback);
                }
            );

            if (isset($responseData['error_detail'])) {
                Log::warning('Falling back to local prediction response', $responseData);

                return response()->json(
                    $this->buildPredictionFallback($dataHistory, $responseData['error_detail']),
                    200
                );
            }

            return response()->json($responseData, 200);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Prediction Crash', 'exception' => $e->getMessage()], 500);
        }
    }

    /**
     * Chat interface for user questions about room climate.
     */
    public function chat(Request $request)
    {
        try {
            $request->validate(['message' => 'required|string|max:500']);

            $latestReading = app(SensorReadingSource::class)->latest();
            $climateContext = $latestReading
                ? "Current conditions - Temp: {$latestReading->temperature} C, Humidity: {$latestReading->humidity}%, Pressure: {$latestReading->pressure} hPa."
                : 'Sensor data is currently unavailable.';

            $message = $request->input('message');
            $fallback = $this->buildChatFallback($message, $latestReading);
            $responseData = $this->resolveCachedResponse(
                'chat',
                [
                    'message' => mb_strtolower(trim($message)),
                    'latest_id' => $latestReading?->id,
                ],
                self::CHAT_CACHE_SECONDS,
                function () use ($climateContext, $message, $fallback) {
                    $aiData = $this->askModel([
                        [
                            'role' => 'system',
                            'content' => "You are a helpful smart room assistant. $climateContext Answer the user's question concisely. Return a JSON object with exactly one key: 'reply'.",
                        ],
                        [
                            'role' => 'user',
                            'content' => $message,
                        ],
                    ]);

                    if (isset($aiData['error_detail'])) {
                        return $aiData;
                    }

                    return $this->mergeWithFallback($aiData, $fallback);
                }
            );

            if (isset($responseData['error_detail'])) {
                Log::warning('Falling back to local chat response', $responseData);

                return response()->json(
                    $this->buildChatFallback($message, $latestReading, $responseData['error_detail']),
                    200
                );
            }

            return response()->json($responseData, 200);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Chat Crash', 'exception' => $e->getMessage()], 500);
        }
    }
}
