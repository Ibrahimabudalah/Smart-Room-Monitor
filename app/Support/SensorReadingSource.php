<?php

namespace App\Support;

use App\Models\SensorReading;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SensorReadingSource
{
    private ?Collection $mockReadings = null;

    public function latest(): ?SensorReading
    {
        if ($this->shouldUseMockData()) {
            return $this->mockReadings()->first();
        }

        return SensorReading::latest()->first();
    }

    public function history(?int $hours = 1): Collection
    {
        if ($this->shouldUseMockData()) {
            return $this->mockReadings()->values();
        }

        return SensorReading::query()
            ->where('created_at', '>=', now()->subHours($hours ?? 1))
            ->orderByDesc('created_at')
            ->get();
    }

    public function recent(int $limit): Collection
    {
        if ($this->shouldUseMockData()) {
            return $this->mockReadings()
                ->take($limit)
                ->reverse()
                ->values();
        }

        return SensorReading::latest()
            ->take($limit)
            ->get()
            ->reverse()
            ->values();
    }

    private function shouldUseMockData(): bool
    {
        $source = config('services.ai_sensor_data.source');

        if ($source === 'mock') {
            return true;
        }

        if ($source === 'database') {
            return false;
        }

        return app()->environment('local') && is_file($this->mockPath());
    }

    private function mockReadings(): Collection
    {
        if ($this->mockReadings !== null) {
            return $this->mockReadings;
        }

        if (!is_file($this->mockPath())) {
            return $this->mockReadings = collect();
        }

        $payload = json_decode(file_get_contents($this->mockPath()), true);
        if (!is_array($payload)) {
            return $this->mockReadings = collect();
        }

        return $this->mockReadings = collect($payload)
            ->map(function (array $row) {
                $reading = new SensorReading();
                $reading->exists = true;
                $reading->forceFill([
                    'id' => $row['id'],
                    'temperature' => $row['temperature'],
                    'humidity' => $row['humidity'],
                    'pressure' => $row['pressure'],
                    'created_at' => Carbon::parse($row['created_at']),
                    'updated_at' => Carbon::parse($row['updated_at']),
                ]);

                return $reading;
            })
            ->sortByDesc(fn(SensorReading $reading) => $reading->created_at?->getTimestamp() ?? 0)
            ->values();
    }

    private function mockPath(): string
    {
        return config('services.ai_sensor_data.mock_path', storage_path('app/private/mock_sensor_readings.json'));
    }
}
