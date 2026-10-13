<?php

namespace Tests\Feature;

use App\Models\CurrentStock;
use App\Models\Department;
use App\Models\Item;
use App\Models\Requisition;
use App\Models\StockIssue;
use App\Models\StockLedger;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class Part6StockIssueOutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * Helper to seed initial stock in Main Store and Secondary Store, and create an HOD-Approved SR.
     */
    protected function createApprovedSrWithStock(float $mainStoreStock = 50.0, float $otherStoreStock = 30.0): array
    {
        $department = Department::where('code', 'DEPT-HK')->firstOrFail();
        $mainStore = Store::where('code', 'STR-MAIN')->firstOrFail();
        $otherStore = Store::where('code', 'STR-HK')->firstOrFail();
        $item1 = Item::where('sku', 'ITM-HK-001')->firstOrFail();
        $item2 = Item::where('sku', 'ITM-HK-002')->firstOrFail();

        $staffHk = User::where('email', 'staff.hk@hotel.local')->firstOrFail();
        $hodHk = User::where('email', 'hod.hk@hotel.local')->firstOrFail();

        CurrentStock::updateOrCreate(
            ['item_id' => $item1->id, 'store_id' => $mainStore->id],
            ['quantity' => $mainStoreStock]
        );
        CurrentStock::updateOrCreate(
            ['item_id' => $item2->id, 'store_id' => $mainStore->id],
            ['quantity' => $mainStoreStock]
        );
        CurrentStock::updateOrCreate(
            ['item_id' => $item1->id, 'store_id' => $otherStore->id],
            ['quantity' => $otherStoreStock]
        );

        Sanctum::actingAs($staffHk);
        $srRes = $this->postJson('/api/requisitions', [
            'type' => 'SR',
            'department_id' => $department->id,
            'store_id' => $mainStore->id,
            'notes' => 'Housekeeping floor replenishment SR',
            'items' => [
                ['item_id' => $item1->id, 'requested_quantity' => 20],
                ['item_id' => $item2->id, 'requested_quantity' => 15],
            ],
        ])->assertCreated();

        $srId = $srRes->json('data.id');

        Sanctum::actingAs($hodHk);
        $this->postJson("/api/requisitions/{$srId}/hod-approve")->assertOk();

        // Verify HOD approval did not deduct stock or create any ledger entry
        $this->assertEquals(
            number_format($mainStoreStock, 3, '.', ''),
            CurrentStock::where('item_id', $item1->id)->where('store_id', $mainStore->id)->value('quantity')
        );
        $this->assertEquals(0, StockLedger::count());

        return [
            'sr' => Requisition::with('items')->findOrFail($srId),
            'department' => $department,
            'mainStore' => $mainStore,
            'otherStore' => $otherStore,
            'item1' => $item1,
            'item2' => $item2,
        ];
    }

    public function test_draft_issue_does_not_deduct_stock_and_posting_partial_then_final_issue_updates_stock_ledger_and_sr_status(): void
    {
        $ctx = $this->createApprovedSrWithStock(50.0, 30.0);
        $sr = $ctx['sr'];
        $mainStore = $ctx['mainStore'];
        $otherStore = $ctx['otherStore'];
        $department = $ctx['department'];
        $item1 = $ctx['item1'];
        $item2 = $ctx['item2'];

        $storeKeeper = User::where('email', 'store.keeper@hotel.local')->firstOrFail();
        $storeManager = User::where('email', 'store.manager@hotel.local')->firstOrFail();

        // 1. Create a Draft Stock Issue for partial quantity (12 of 20 item1, 15 of 15 item2)
        Sanctum::actingAs($storeKeeper);
        $draftRes = $this->postJson('/api/stock-issues', [
            'requisition_id' => $sr->id,
            'store_id' => $mainStore->id,
            'department_id' => $department->id,
            'notes' => 'First batch issue to Housekeeping',
            'items' => [
                ['item_id' => $item1->id, 'issued_quantity' => 12],
                ['item_id' => $item2->id, 'issued_quantity' => 15],
            ],
        ])->assertCreated();

        $issue1Id = $draftRes->json('data.id');
        $this->assertEquals(StockIssue::STATUS_DRAFT, $draftRes->json('data.status'));

        // Verify Draft Stock Issue does NOT deduct stock or write ledger entries
        $this->assertEquals(
            '50.000',
            CurrentStock::where('item_id', $item1->id)->where('store_id', $mainStore->id)->value('quantity')
        );
        $this->assertEquals(0, StockLedger::count());
        $this->assertEquals(Requisition::STATUS_HOD_APPROVED, $sr->fresh()->status);

        // 2. Post the Draft Stock Issue
        Sanctum::actingAs($storeManager);
        $postRes = $this->postJson("/api/stock-issues/{$issue1Id}/post")->assertOk();
        $this->assertEquals(StockIssue::STATUS_POSTED, $postRes->json('data.status'));
        $this->assertEquals($storeManager->id, $postRes->json('data.issued_by'));

        // Verify stock decreased in Main Store (50 - 12 = 38 for item1, 50 - 15 = 35 for item2)
        $this->assertEquals(
            '38.000',
            CurrentStock::where('item_id', $item1->id)->where('store_id', $mainStore->id)->value('quantity')
        );
        $this->assertEquals(
            '35.000',
            CurrentStock::where('item_id', $item2->id)->where('store_id', $mainStore->id)->value('quantity')
        );

        // Verify stock in Secondary Store (STR-FNB) is completely unchanged
        $this->assertEquals(
            '30.000',
            CurrentStock::where('item_id', $item1->id)->where('store_id', $otherStore->id)->value('quantity')
        );

        // Verify StockLedger OUT entries with department/cost center attribution
        $ledgerItem1 = StockLedger::where('reference_type', StockLedger::REF_STOCK_ISSUE)
            ->where('reference_id', $issue1Id)
            ->where('item_id', $item1->id)
            ->firstOrFail();

        $this->assertEquals(StockLedger::TYPE_OUT, $ledgerItem1->transaction_type);
        $this->assertEquals('-12.000', $ledgerItem1->quantity);
        $this->assertEquals('38.000', $ledgerItem1->balance_after);
        $this->assertEquals($department->id, $ledgerItem1->department_id);
        $this->assertEquals($storeManager->id, $ledgerItem1->created_by);

        // Verify SR status transitioned to Partially Issued
        $this->assertEquals(Requisition::STATUS_PARTIALLY_ISSUED, $sr->fresh()->status);

        // 3. Create & immediately post the remaining 8 units of item1
        Sanctum::actingAs($storeKeeper);
        $issue2Res = $this->postJson('/api/stock-issues', [
            'requisition_id' => $sr->id,
            'post_immediately' => true,
            'notes' => 'Second batch completing SR',
            'items' => [
                ['item_id' => $item1->id, 'issued_quantity' => 8],
            ],
        ])->assertCreated();

        $this->assertEquals(StockIssue::STATUS_POSTED, $issue2Res->json('data.status'));
        $this->assertEquals(
            '30.000',
            CurrentStock::where('item_id', $item1->id)->where('store_id', $mainStore->id)->value('quantity')
        );
        $this->assertEquals(Requisition::STATUS_ISSUED, $sr->fresh()->status);
        $this->assertEquals(3, StockLedger::where('transaction_type', StockLedger::TYPE_OUT)->count());
    }

    public function test_non_approved_sr_insufficient_stock_excess_sr_quantity_and_double_posting_are_rejected(): void
    {
        $ctx = $this->createApprovedSrWithStock(15.0, 30.0); // Only 15 units in stock, while SR requests 20 of item1
        $sr = $ctx['sr'];
        $department = $ctx['department'];
        $mainStore = $ctx['mainStore'];
        $item1 = $ctx['item1'];
        $item2 = $ctx['item2'];

        $staffHk = User::where('email', 'staff.hk@hotel.local')->firstOrFail();
        $storeKeeper = User::where('email', 'store.keeper@hotel.local')->firstOrFail();

        // 1. Create a Pending SR (not HOD approved yet) and verify issuing against it is rejected (422)
        Sanctum::actingAs($staffHk);
        $pendingSrRes = $this->postJson('/api/requisitions', [
            'type' => 'SR',
            'department_id' => $department->id,
            'store_id' => $mainStore->id,
            'items' => [
                ['item_id' => $item1->id, 'requested_quantity' => 5],
            ],
        ])->assertCreated();
        $pendingSrId = $pendingSrRes->json('data.id');

        Sanctum::actingAs($storeKeeper);
        $this->postJson('/api/stock-issues', [
            'requisition_id' => $pendingSrId,
            'items' => [
                ['item_id' => $item1->id, 'issued_quantity' => 5],
            ],
        ])->assertStatus(422);

        // 2. Zero or negative quantities are rejected (422)
        $this->postJson('/api/stock-issues', [
            'requisition_id' => $sr->id,
            'items' => [
                ['item_id' => $item1->id, 'issued_quantity' => 0],
            ],
        ])->assertStatus(422);

        // 3. Quantity exceeding SR requested balance (25 > 20) is rejected (422)
        $this->postJson('/api/stock-issues', [
            'requisition_id' => $sr->id,
            'items' => [
                ['item_id' => $item1->id, 'issued_quantity' => 25],
            ],
        ])->assertStatus(422);

        // 4. Quantity within SR balance (18 <= 20) but exceeding available stock (18 > 15) is rejected (422)
        $this->postJson('/api/stock-issues', [
            'requisition_id' => $sr->id,
            'items' => [
                ['item_id' => $item1->id, 'issued_quantity' => 18],
            ],
        ])->assertStatus(422);

        // 5. Two competing Draft Stock Issues of 10 units each when available stock is 15:
        // Both drafts can be created while stock is 15, but after the first is posted (stock drops to 5),
        // posting the second draft must fail (422) and never drive current_stocks negative!
        $draftA = $this->postJson('/api/stock-issues', [
            'requisition_id' => $sr->id,
            'items' => [
                ['item_id' => $item1->id, 'issued_quantity' => 10],
            ],
        ])->assertCreated();

        $draftB = $this->postJson('/api/stock-issues', [
            'requisition_id' => $sr->id,
            'items' => [
                ['item_id' => $item1->id, 'issued_quantity' => 10],
            ],
        ])->assertCreated();

        $draftAId = $draftA->json('data.id');
        $draftBId = $draftB->json('data.id');

        // Post Draft A -> succeeds, stock becomes 5.000
        $this->postJson("/api/stock-issues/{$draftAId}/post")->assertOk();
        $this->assertEquals(
            '5.000',
            CurrentStock::where('item_id', $item1->id)->where('store_id', $mainStore->id)->value('quantity')
        );

        // Re-posting Draft A (already Posted) must fail (422) without double-deducting
        $this->postJson("/api/stock-issues/{$draftAId}/post")->assertStatus(422);
        $this->assertEquals(
            '5.000',
            CurrentStock::where('item_id', $item1->id)->where('store_id', $mainStore->id)->value('quantity')
        );

        // Posting Draft B (wants 10 units, only 5 left in stock) must fail (422) and keep stock at 5.000
        $this->postJson("/api/stock-issues/{$draftBId}/post")->assertStatus(422);
        $this->assertEquals(
            '5.000',
            CurrentStock::where('item_id', $item1->id)->where('store_id', $mainStore->id)->value('quantity')
        );
    }

    public function test_unauthorized_users_cannot_create_or_post_issues_and_forged_actor_or_stock_is_ignored(): void
    {
        $ctx = $this->createApprovedSrWithStock(50.0, 30.0);
        $sr = $ctx['sr'];
        $item1 = $ctx['item1'];

        $staffHk = User::where('email', 'staff.hk@hotel.local')->firstOrFail();
        $hodHk = User::where('email', 'hod.hk@hotel.local')->firstOrFail();
        $procurement = User::where('email', 'procurement@hotel.local')->firstOrFail();
        $storeKeeper = User::where('email', 'store.keeper@hotel.local')->firstOrFail();

        // Department staff, HOD, and Procurement Officer cannot create Stock Issues (403)
        foreach ([$staffHk, $hodHk, $procurement] as $unauthorizedUser) {
            Sanctum::actingAs($unauthorizedUser);
            $this->postJson('/api/stock-issues', [
                'requisition_id' => $sr->id,
                'items' => [
                    ['item_id' => $item1->id, 'issued_quantity' => 5],
                ],
            ])->assertForbidden();
        }

        // Store Keeper creates and posts an issue while attempting to forge issued_by and balance_after
        Sanctum::actingAs($storeKeeper);
        $res = $this->postJson('/api/stock-issues', [
            'requisition_id' => $sr->id,
            'post_immediately' => true,
            'issued_by' => $hodHk->id,
            'created_by' => $hodHk->id,
            'items' => [
                [
                    'item_id' => $item1->id,
                    'issued_quantity' => 5,
                    'available_stock' => 999999,
                    'balance_after' => 999999,
                ],
            ],
        ])->assertCreated();

        // Server derives actor from authenticated user and computes exact stock balance (50 - 5 = 45)
        $this->assertEquals($storeKeeper->id, $res->json('data.created_by'));
        $this->assertEquals($storeKeeper->id, $res->json('data.issued_by'));
        $this->assertEquals(
            '45.000',
            CurrentStock::where('item_id', $item1->id)->where('store_id', $sr->store_id)->value('quantity')
        );
    }

    public function test_simulated_database_failure_during_issue_posting_rolls_back_all_changes_atomically(): void
    {
        $ctx = $this->createApprovedSrWithStock(50.0, 30.0);
        $sr = $ctx['sr'];
        $mainStore = $ctx['mainStore'];
        $item1 = $ctx['item1'];

        $storeKeeper = User::where('email', 'store.keeper@hotel.local')->firstOrFail();

        Sanctum::actingAs($storeKeeper);
        $draftRes = $this->postJson('/api/stock-issues', [
            'requisition_id' => $sr->id,
            'items' => [
                ['item_id' => $item1->id, 'issued_quantity' => 10],
            ],
        ])->assertCreated();

        $issueId = $draftRes->json('data.id');

        // Simulate a database failure when inserting into stock_ledger during postIssue
        StockLedger::creating(function () {
            throw new RuntimeException('Simulated database failure during Stock Issue OUT ledger write.');
        });

        try {
            $this->postJson("/api/stock-issues/{$issueId}/post");
        } catch (\Throwable $e) {
            // Expected exception
        } finally {
            StockLedger::flushEventListeners();
        }

        // Verify complete rollback:
        // 1. StockIssue remains Draft with null issued_by/issued_at
        $issueAfter = StockIssue::findOrFail($issueId);
        $this->assertEquals(StockIssue::STATUS_DRAFT, $issueAfter->status);
        $this->assertNull($issueAfter->issued_by);
        $this->assertNull($issueAfter->issued_at);

        // 2. SR status remains HOD Approved and issued_quantity remains 0.000
        $srAfter = Requisition::with('items')->findOrFail($sr->id);
        $this->assertEquals(Requisition::STATUS_HOD_APPROVED, $srAfter->status);
        foreach ($srAfter->items as $srItem) {
            $this->assertEquals('0.000', $srItem->issued_quantity);
        }

        // 3. CurrentStock remains untouched at 50.000 and StockLedger has 0 rows
        $this->assertEquals(
            '50.000',
            CurrentStock::where('item_id', $item1->id)->where('store_id', $mainStore->id)->value('quantity')
        );
        $this->assertEquals(0, StockLedger::count());
    }
}
