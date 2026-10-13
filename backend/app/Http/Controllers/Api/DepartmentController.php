<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    private const ALLOWED_SORT_FIELDS = [
        'id',
        'code',
        'name',
        'is_active',
        'created_at',
    ];

    /**
     * Paginated list of departments/cost centers with search, active filter, and allow-listed sorting.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
            'sort_by' => ['nullable', 'string', Rule::in(self::ALLOWED_SORT_FIELDS)],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc', 'ASC', 'DESC'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Department::query();

        if (! empty($validated['search'])) {
            $search = trim($validated['search']);
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active') && $request->input('is_active') !== '' && $request->input('is_active') !== null) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $sortBy = $validated['sort_by'] ?? 'code';
        if (! in_array($sortBy, self::ALLOWED_SORT_FIELDS, true)) {
            $sortBy = 'code';
        }
        $sortDir = strtolower($validated['sort_dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $perPage = (int) ($validated['per_page'] ?? 15);
        $paginator = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

        return response()->json($paginator);
    }

    /**
     * Store a new department / cost center.
     */
    public function store(Request $request): JsonResponse
    {
        if ($request->has('code') && is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper(trim($request->input('code')))]);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:departments,code'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $validated['is_active'] = $validated['is_active'] ?? true;

        $department = Department::create($validated);

        return response()->json([
            'message' => 'Department created successfully.',
            'data' => $department,
        ], 201);
    }

    /**
     * Display department details.
     */
    public function show(Department $department): JsonResponse
    {
        return response()->json([
            'data' => $department,
        ]);
    }

    /**
     * Update an existing department.
     */
    public function update(Request $request, Department $department): JsonResponse
    {
        if ($request->has('code') && is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper(trim($request->input('code')))]);
        }

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('departments', 'code')->ignore($department->id),
            ],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $department->update($validated);

        return response()->json([
            'message' => 'Department updated successfully.',
            'data' => $department->fresh(),
        ]);
    }

    /**
     * Deactivate a department to preserve historical transaction references.
     */
    public function deactivate(Department $department): JsonResponse
    {
        $department->update(['is_active' => false]);

        return response()->json([
            'message' => 'Department deactivated successfully.',
            'data' => $department->fresh(),
        ]);
    }

    /**
     * Delete a department only if it is not referenced by users, requisitions, or stock ledger entries.
     */
    public function destroy(Department $department): JsonResponse
    {
        $isReferenced = $department->users()->exists()
            || $department->requisitions()->exists()
            || $department->stockLedgerEntries()->exists();

        if ($isReferenced) {
            return response()->json([
                'message' => 'Cannot delete department because it is referenced by existing users, requisitions, or inventory records. Please deactivate it instead.',
                'errors' => [
                    'department' => ['Department is referenced by historical records and cannot be deleted.'],
                ],
            ], 422);
        }

        $department->delete();

        return response()->json([
            'message' => 'Department deleted successfully.',
        ]);
    }
}
