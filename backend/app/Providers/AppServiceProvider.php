<?php

namespace App\Providers;

use App\Models\GoodsReceivingNote;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\StockLedger;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::morphMap([
            StockLedger::REF_GRN => GoodsReceivingNote::class,
            'REQUISITION' => Requisition::class,
            'PURCHASE_ORDER' => PurchaseOrder::class,
        ]);
    }
}
