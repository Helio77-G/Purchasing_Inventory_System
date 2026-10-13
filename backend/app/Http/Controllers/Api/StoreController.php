<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StoreController extends Controller
{
    private const ALLOWED_SORT_FIELDS = [
        'id',
        'code',
        'name',
        'location',
        'is_active',
        'created_at',
    ];

    /**
     * Paginated list of stores with search, active filter, and allow-listed sorting.
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

        $query = Store::query();

        if (! empty($validated['search'])) {
            $search = trim($validated['search']);
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
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
     * Store a new warehouse/store.
     */
    public function store(Request $request): JsonResponse
    {
        if ($request->has('code') && is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper(trim($request->input('code')))]);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:stores,code'],
            'name' => ['required', 'string', 'max:150'],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $validated['is_active'] = $validated['is_active'] ?? true;

        $store = Store::create($validated);

        return response()->json([
            'message' => 'Store created successfully.',
            'data' => $store,
        ], 201);
    }

    /**
     * Display store details.
     */
    public function show(Store $store): JsonResponse
    {
        return response()->json([
            'data' => $store,
        ]);
    }

    /**
     * Update an existing store.
     */
    public function update(Request $request, Store $store): JsonResponse
    {
        if ($request->has('code') && is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper(trim($request->input('code')))]);
        }

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('stores', 'code')->ignore($store->id),
            ],
            'name' => ['required', 'string', 'max:150'],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $store->update($validated);

        return response()->json([
            'message' => 'Store updated successfully.',
            'data' => $store->fresh(),
        ]);
    }

    /**
     * Deactivate a store to preserve historical transaction references.
     */
    public function deactivate(Store $store): JsonResponse
    {
        $store->update(['is_active' => false]);

        return response()->json([
            'message' => 'Store deactivated successfully.',
            'data' => $store->fresh(),
        ]);
    }

    /**
     * Delete a store only if it is not referenced by users, requisitions, GRNs, or stock records.
     */
    public function destroy(Store $store): JsonResponse
    {
        $isReferenced = $store->users()->exists()
            || $store->requisitions()->exists()
            || $store->goodsReceivingNotes()->exists()
            || $store->currentStocks()->exists()
            || $store->stockLedgerEntries()->exists();

        if ($isReferenced) {
            return response()->json([
                'message' => 'Cannot delete store because it is referenced by existing users, requisitions, or inventory records. Please deactivate it instead.',
                'errors' => [
                    'store' => ['Store is referenced by historical records and cannot be deleted.'],
                ],
            ], 422);
        }

        $store->delete();

        return response()->json([
            'message' => 'Store deleted successfully.',
        ]);
    }
}
