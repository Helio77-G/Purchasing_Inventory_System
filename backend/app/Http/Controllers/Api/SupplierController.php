<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    private const ALLOWED_SORT_FIELDS = [
        'id',
        'code',
        'name',
        'contact_person',
        'email',
        'is_active',
        'created_at',
    ];

    /**
     * Paginated list of suppliers with search, active filter, and allow-listed sorting.
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

        $query = Supplier::query();

        if (! empty($validated['search'])) {
            $search = trim($validated['search']);
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
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
     * Store a new supplier.
     */
    public function store(Request $request): JsonResponse
    {
        if ($request->has('code') && is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper(trim($request->input('code')))]);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:suppliers,code'],
            'name' => ['required', 'string', 'max:200'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $validated['is_active'] = $validated['is_active'] ?? true;

        $supplier = Supplier::create($validated);

        return response()->json([
            'message' => 'Supplier created successfully.',
            'data' => $supplier,
        ], 201);
    }

    /**
     * Display supplier details.
     */
    public function show(Supplier $supplier): JsonResponse
    {
        return response()->json([
            'data' => $supplier,
        ]);
    }

    /**
     * Update an existing supplier.
     */
    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        if ($request->has('code') && is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper(trim($request->input('code')))]);
        }

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('suppliers', 'code')->ignore($supplier->id),
            ],
            'name' => ['required', 'string', 'max:200'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $supplier->update($validated);

        return response()->json([
            'message' => 'Supplier updated successfully.',
            'data' => $supplier->fresh(),
        ]);
    }

    /**
     * Deactivate a supplier to preserve historical transaction references.
     */
    public function deactivate(Supplier $supplier): JsonResponse
    {
        $supplier->update(['is_active' => false]);

        return response()->json([
            'message' => 'Supplier deactivated successfully.',
            'data' => $supplier->fresh(),
        ]);
    }

    /**
     * Delete a supplier only if it is not referenced by any purchase orders.
     */
    public function destroy(Supplier $supplier): JsonResponse
    {
        if ($supplier->purchaseOrders()->exists()) {
            return response()->json([
                'message' => 'Cannot delete supplier because it is referenced by historical purchase orders. Please deactivate it instead.',
                'errors' => [
                    'supplier' => ['Supplier is referenced by historical purchase orders and cannot be deleted.'],
                ],
            ], 422);
        }

        $supplier->delete();

        return response()->json([
            'message' => 'Supplier deleted successfully.',
        ]);
    }
}
