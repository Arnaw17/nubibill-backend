<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class Customer extends FirestoreModel
{
    protected $table = 'customers';

    /**
     * Scope for frequent purchasers (>= 3 purchases within past 90 days or lifetime).
     */
    public function scopeFrequent(Builder $query, string $businessId, int $days = 90, int $minPurchases = 3): Builder
    {
        $cutoff = now()->subDays($days);
        $customerIds = DB::table('invoices')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'CANCELLED');
            })
            ->where('created_at', '>=', $cutoff)
            ->selectRaw("COALESCE(customer_id, JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.customerId'))) as cust_id")
            ->groupBy('cust_id')
            ->havingRaw('COUNT(*) >= ?', [$minPurchases])
            ->pluck('cust_id')
            ->filter()
            ->all();

        return $query->whereIn('id', $customerIds);
    }

    /**
     * Scope for overdue customers (due > 0 and overdue invoices or past credit period).
     */
    public function scopeOverdue(Builder $query, string $businessId, int $creditDays = 30): Builder
    {
        $cutoff = now()->subDays($creditDays);

        $owingCustomerIds = DB::table('customer_balances')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->whereRaw("CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.duePaise')), 0) AS SIGNED) > 0")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.customerId')) as cust_id")
            ->pluck('cust_id')
            ->filter()
            ->all();

        return $query->whereIn('id', $owingCustomerIds);
    }

    /**
     * Scope for high purchase customers (lifetime purchases exceeding threshold).
     */
    public function scopeHighPurchase(Builder $query, string $businessId, int $thresholdPaise = 5000000): Builder
    {
        $customerIds = DB::table('invoices')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'CANCELLED');
            })
            ->selectRaw("COALESCE(customer_id, JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.customerId'))) as cust_id")
            ->groupBy('cust_id')
            ->havingRaw("SUM(CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.grandTotalPaise')), JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.totalAmount')), JSON_UNQUOTE(JSON_EXTRACT(payload, '$.fields.grandTotal')), 0) AS SIGNED)) >= ?", [$thresholdPaise])
            ->pluck('cust_id')
            ->filter()
            ->all();

        return $query->whereIn('id', $customerIds);
    }
}
