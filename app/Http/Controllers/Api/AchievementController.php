<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use Illuminate\Http\Request;

class AchievementController extends Controller
{
    public function index()
    {
        return response()->json(Achievement::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'club_id' => 'required|integer',
            'title' => 'required|string|max:255',
            'competition' => 'nullable|string|max:255',
            'award_date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        $validated['submitted_by'] = $request->user() ? $request->user()->id : 1;
        $validated['status'] = 'Pending';

        $achievement = Achievement::create($validated);
        return response()->json($achievement, 201);
    }
}
