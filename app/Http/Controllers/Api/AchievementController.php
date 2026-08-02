<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use App\Services\MalwareScannerService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AchievementController extends Controller
{
    protected MalwareScannerService $scannerService;

    public function __construct(MalwareScannerService $scannerService)
    {
        $this->scannerService = $scannerService;
    }

    public function index(): JsonResponse
    {
        $achievements = Achievement::with(['club', 'submitter', 'verifier'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $achievements,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'club_id'     => 'required|exists:clubs,id',
            'title'       => 'required|string|max:250',
            'competition' => 'required|string|max:250',
            'award_date'  => 'required|date',
            'proof_file'  => 'nullable|file|max:10240', // max 10MB
            'notes'       => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $proofPath = null;

        // Security check and malware scan on proof file upload
        if ($request->hasFile('proof_file')) {
            try {
                $proofPath = $this->scannerService->scanAndStore($request->file('proof_file'), 'achievements');
            } catch (Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Upload Security Violation: ' . $e->getMessage(),
                ], 400);
            }
        }

        $achievement = Achievement::create([
            'club_id'      => $request->club_id,
            'submitted_by' => $request->user()->id,
            'title'        => $request->title,
            'competition'  => $request->competition,
            'award_date'   => $request->award_date,
            'proof_file'   => $proofPath,
            'status'       => 'Pending',
            'notes'        => $request->notes,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Achievement submitted and scanned successfully for approval.',
            'data' => $achievement,
        ], 201);
    }
}
