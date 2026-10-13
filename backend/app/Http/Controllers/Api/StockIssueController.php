<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CurrentStock;
use App\Models\StockIssue;
use App\Services\StockIssueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockIssueController extends Controller
{
    public const ALLOWED_SORT_COLUMNS = [
        'id',
        'issue_number',
        'status',
        'issued_at',
        'created_at',
    ];

    public function __construct(
        protected StockIssueService $stockIssueService
    ) {}

    /**
     * List Bulk Stock Issues with search, status/store/department/SR filters, and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = StockIssue::query()
            ->with([
                'requisition.department',
                'store',
                'department',
                'creator',
                'issuer',
            ])
            ->withCount('items');

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('issue_number', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('requisition', function ($rq) use ($search) {
                        $rq->where('requisition_number', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('store_id')) {
            $query->where('store_id', (int) $request->input('store_id'));
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', (int) $request->input('department_id'));
        }

        if ($request->filled('requisition_id')) {
            $query->where('requisition_id', (int) $request->input('requisition_id'));
        }

        $sortBy = (string) $request->input('sort_by', 'id');
        $sortDir = strtolower((string) $request->input('sort_dir', 'desc'));

        if (! in_array($sortBy, self::ALLOWED_SORT_COLUMNS, true)) {
            return response()->json([
                'message' => 'Invalid sort_by column.',
                'errors' => ['sort_by' => ['The selected sort_by column is not allowed.']],
            ], 422);
        }

        if (! in_array($sortDir, ['asc', 'desc'], true)) {
            $sortDir = 'desc';
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $issues = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

        return response()->json($issues);
    }

    /**
     * Create a new Bulk Stock Issue (Draft or immediately Posted if post_immediately = true)
     * from an eligible HOD-Approved or Partially-Issued Store Requisition.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'requisition_id' => ['required', 'integer', Rule::exists('requisitions', 'id')],
            'store_id' => ['nullable', 'integer', Rule::exists('stores', 'id')],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'notes' => ['nullable', 'string', 'max:2000'],
            'post_immediately' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')],
            'items.*.issued_quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        $issue = $this->stockIssueService->createIssue($validated, $request->user());

        $message = $issue->status === StockIssue::STATUS_POSTED
            ? 'Bulk Stock Issue created and posted. Stock OUT recorded.'
            : 'Bulk Stock Issue draft created successfully.';

        return response()->json([
            'message' => $message,
            'data' => $issue,
        ], 201);
    }

    /**
     * Show a single Stock Issue with items, SR quantities, available store stock, and Stock Ledger OUT entries.
     */
    public function show(StockIssue $stockIssue): JsonResponse
    {
        $stockIssue->load([
            'requisition.department',
            'requisition.requester',
            'store',
            'department',
            'creator',
            'issuer',
            'items.item',
            'items.requisitionItem',
            'stockLedgerEntries.actor',
            'stockLedgerEntries.item',
            'stockLedgerEntries.department',
        ]);

        $stockMap = CurrentStock::where('store_id', $stockIssue->store_id)
            ->whereIn('item_id', $stockIssue->items->pluck('item_id'))
            ->pluck('quantity', 'item_id');

        foreach ($stockIssue->items as $issueItem) {
            $issueItem->setAttribute(
                'available_stock',
                number_format((float) ($stockMap[$issueItem->item_id] ?? 0), 3, '.', '')
            );
        }

        return response()->json([
            'data' => $stockIssue,
        ]);
    }

    /**
     * Post/Approve a Draft Stock Issue: atomically deducts CurrentStock and creates StockLedger OUT entries.
     */
    public function post(Request $request, StockIssue $stockIssue): JsonResponse
    {
        $posted = $this->stockIssueService->postIssue($stockIssue, $request->user());

        return response()->json([
            'message' => 'Stock Issue posted successfully. Stock OUT recorded.',
            'data' => $posted,
        ]);
    }
}
