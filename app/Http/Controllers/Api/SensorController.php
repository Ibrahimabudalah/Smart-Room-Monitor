<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SensorReading;

class SensorController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'temperature' => 'required|numeric',
            'humidity' => 'required|numeric',
            'pressure' => 'required|numeric',
        ]);

        $reading = SensorReading::create($data);

        return response()->json([
            'message' => 'Saved',
            'data' => $reading
        ]);
    }

    public function latest()
    {
        return SensorReading::latest()->first();
    }

    public function history()
    {
        return SensorReading::latest()->take(50)->get();
    }
}
