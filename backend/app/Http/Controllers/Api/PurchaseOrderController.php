<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PurchaseOrderController extends Controller
{
    private const ALLOWED_SORT_FIELDS = [
        'id',
        'po_number',
        'status',
        'total_amount',
        'created_at',
    ];

    public function __construct(
        private readonly PurchaseOrderService $purchaseOrderService
    ) {}

    /**
     * Paginated list of Purchase Orders with filters and allow-listed sorting.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:32'],
            'supplier_id' => ['nullable', 'integer'],
            'requisition_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:150'],
            'sort_by' => ['nullable', 'string', Rule::in(self::ALLOWED_SORT_FIELDS)],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc', 'ASC', 'DESC'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = PurchaseOrder::with([
            'requisition.department',
            'supplier',
            'creator.role',
            'sender.role',
            'items.item',
        ]);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['supplier_id'])) {
            $query->where('supplier_id', (int) $validated['supplier_id']);
        }

        if (! empty($validated['requisition_id'])) {
            $query->where('requisition_id', (int) $validated['requisition_id']);
        }

        if (! empty($validated['search'])) {
            $search = trim($validated['search']);
            $query->where(function ($q) use ($search) {
                $q->where('po_number', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $sortBy = $validated['sort_by'] ?? 'id';
        if (! in_array($sortBy, self::ALLOWED_SORT_FIELDS, true)) {
            $sortBy = 'id';
        }
        $sortDir = strtolower($validated['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $perPage = (int) ($validated['per_page'] ?? 15);
        $paginator = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

        return response()->json($paginator);
    }

    /**
     * Create a new Draft Purchase Order from an eligible GM-Approved Purchase Requisition.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'requisition_id' => ['required', 'integer'],
            'supplier_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'distinct'],
            'items.*.ordered_quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'gt:0'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        $po = $this->purchaseOrderService->createFromRequisition($request->user(), $validated);

        return response()->json([
            'message' => 'Purchase Order created successfully.',
            'data' => $po,
        ], 201);
    }

    /**
     * Display Purchase Order details with line items, supplier, PR source, and GRNs.
     */
    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $purchaseOrder->load([
            'requisition.department',
            'supplier',
            'creator.role',
            'sender.role',
            'items.item',
            'items.requisitionItem',
            'goodsReceivingNotes.store',
        ]);

        return response()->json([
            'data' => $purchaseOrder,
        ]);
    }

    /**
     * Update an eligible Draft Purchase Order.
     */
    public function update(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'distinct'],
            'items.*.ordered_quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'gt:0'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        $updated = $this->purchaseOrderService->updatePurchaseOrder($request->user(), $purchaseOrder, $validated);

        return response()->json([
            'message' => 'Purchase Order updated successfully.',
            'data' => $updated,
        ]);
    }

    /**
     * Transition a Draft Purchase Order to Sent.
     */
    public function send(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $sent = $this->purchaseOrderService->sendPurchaseOrder($request->user(), $purchaseOrder);

        return response()->json([
            'message' => 'Purchase Order sent to supplier.',
            'data' => $sent,
        ]);
    }

    /**
     * Controlled status transition endpoint.
     * Prevents clients from manually declaring Partial Received or Fully Received without approved GRNs.
     */
    public function updateStatus(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(PurchaseOrder::ALLOWED_STATUSES)],
        ]);

        $targetStatus = $validated['status'];

        if (in_array($targetStatus, [PurchaseOrder::STATUS_PARTIAL_RECEIVED, PurchaseOrder::STATUS_FULLY_RECEIVED], true)) {
            throw ValidationException::withMessages([
                'status' => ['Receipt statuses [Partial Received / Fully Received] are derived automatically from approved GRNs and cannot be set manually.'],
            ]);
        }

        if ($targetStatus === PurchaseOrder::STATUS_SENT) {
            return $this->send($request, $purchaseOrder);
        }

        throw ValidationException::withMessages([
            'status' => ["Transition from [{$purchaseOrder->status}] to [{$targetStatus}] is not permitted."],
        ]);
    }
}
