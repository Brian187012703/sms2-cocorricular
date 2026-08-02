<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class StudentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Student::query();

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('course')) {
            $query->where('course', 'like', '%' . $request->course . '%');
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('section', 'like', "%{$search}%");
            });
        }

        $students = $query->orderBy('last_name')->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $students,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'birthday'   => 'required|date',
            'course'     => 'required|string|max:150',
            'year_level' => 'required|string|max:50',
            'section'    => 'required|string|max:50',
            'phone'      => 'required|string|max:20',
            'status'     => 'in:Active,Inactive',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $student = Student::create($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Student record created successfully.',
            'data' => $student,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $student = Student::find($id);

        if (!$student) {
            return response()->json(['success' => false, 'message' => 'Student record not found.'], 404);
        }

        return response()->json(['success' => true, 'data' => $student]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $student = Student::find($id);

        if (!$student) {
            return response()->json(['success' => false, 'message' => 'Student record not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|string|max:100',
            'last_name'  => 'sometimes|string|max:100',
            'birthday'   => 'sometimes|date',
            'course'     => 'sometimes|string|max:150',
            'year_level' => 'sometimes|string|max:50',
            'section'    => 'sometimes|string|max:50',
            'phone'      => 'sometimes|string|max:20',
            'status'     => 'sometimes|in:Active,Inactive',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $student->update($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Student record updated successfully.',
            'data' => $student,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $student = Student::find($id);

        if (!$student) {
            return response()->json(['success' => false, 'message' => 'Student record not found.'], 404);
        }

        $student->delete();

        return response()->json([
            'success' => true,
            'message' => 'Student record deleted successfully.',
        ]);
    }
}
