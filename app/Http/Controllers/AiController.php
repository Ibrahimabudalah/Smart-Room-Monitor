<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\SensorReading;

class AiController extends Controller
{
    /**
     * Gives suggestions based on the data from sensors.
     * @return response - AI insights, otherwise an error message
     */
    public function getInsights()
    {
        $latestReading = SensorReading::latest()->first();

        if (!$latestReading) {
            return response()->json(['error' => 'No sensor data available'], 404);
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.github.token'),
            'Content-Type' => 'application/json',
        ])->post('https://models.github.ai/inference/chat/completions', [
            'model' => 'gpt-4o-mini',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a smart room assistant. Ideal room temperature is between 20 degrees celcius and 24 degrees celcius. Ideal humidity is between 40% and 60%. Analyze the following sensor data and return a JSON object with exactly two keys: "issue" (a brief summary of any problem, or "none") and "suggestion" (what the user should do).'
                ],
                [
                    'role' => 'user',
                    'content' => 'Current reading: ' . json_encode([
                        'temperature' => $latestReading->temperature,
                        'humidity' => $latestReading->humidity,
                        'pressure' => $latestReading->pressure,
                    ])
                ]
            ]
        ]);

        if ($response->successful()) {
            $aiData = json_decode($response->json('choices.0.message.content'));
            return response()->json($aiData, 200);
        }

        return response()->json(['error' => 'AI request failed'], 500);
    }

    /**
     * Predicts future conditions based on the last 20 sensor readings.
     * @return response - AI prediction, otherwise an error message
     */
    public function getPrediction()
    {
        $dataHistory = SensorReading::latest()->take(20)->get()->reverse()->values();

        if ($dataHistory->isEmpty()) {
            return response()->json(['error' => 'No enough sensor data to predict'], 404);
        }

        //Format the data as an array
        $formattedData = $dataHistory->map(function ($reading) {
            return [
                'temp' => $reading->temperature,
                'humidity' => $reading->humidity,
                'pressure' => $reading->pressure,
                'time' => $reading->created_at ? $reading->created_at->format('H:i') : 'unknown'
            ];
        });

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.github.token'),
            'Content-Type' => 'application/json',
        ])->post('https://models.github.ai/inference/chat/completions', [
            'model' => 'gpt-4o-mini',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a smart room predictive AI. You will be given a chronological list of the last 20 climate readings. Analyze the trajectory of the temperature, humidity, and pressure (pressure changes may indicate outside weather shifts). Return a JSON object with exactly three keys: "trend" (a short string describing the trajectory), "estimated_temp_in_1_hour" (a numerical guess), and "advice" (what the user should expect or do).'
                ],
                [
                    'role' => 'user',
                    'content' => 'Historical data: ' . json_encode($formattedData)
                ]
            ]
        ]);

        if ($response->successful()) {
            $aiData = json_decode($response->json('choices.0.message.content'));
            return response()->json($aiData, 200);
        }

        return response()->json(['error' => 'AI request failed'], 500);
    }

    /**
     * A chat the user can ask questions about room climate conditions based on their data.
     * @param Request $request - the message the user wants to ask AI
     * @return response - AI answer, otherwise an error message
     */
    public function chat(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:500'
        ]);

        $userMessage = $request->input('message');
        $latestReading = SensorReading::latest()->first();

        //Check if there's sensor data available to read
        $climateContext = $latestReading
            ? "Current room conditions - Temp: {$latestReading->temperature}°C, Humidity: {$latestReading->humidity}%, Pressure: {$latestReading->pressure}hPa."
            : "Sensor data is currently unavailable.";

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.github.token'),
            'Content-Type' => 'application/json',
        ])->post('https://models.github.ai/inference/chat/completions', [
            'model' => 'gpt-4o-mini',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "You are a helpful smart room assistant. $climateContext Answer the user's question directly and concisely based on these conditions. Return a JSON object with exactly one key: 'reply'."
                ],
                [
                    'role' => 'user',
                    'content' => $userMessage
                ]
            ]
        ]);

        if ($response->successful()) {
            $aiData = json_decode($response->json('choices.0.message.content'));
            return response()->json($aiData, 200);
        }

        return response()->json(['error' => 'AI chat failed'], 500);
    }
}
