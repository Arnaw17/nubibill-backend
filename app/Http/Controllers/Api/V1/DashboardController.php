<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\EmployeePermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Get aggregated dashboard summary & KPIs.
     */
    public function metrics(Request $request, EmployeePermissionService $permissions): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $outletId = $request->query('outlet_id') ?? $request->query('outletId') ?? $request->header('X-Outlet-Id');

        $user = $request->user();
        if (! $businessId) {
            $businessId = $user ? $user->defaultBusinessId() : null;
        }

        if (! $businessId || ($user && ! $user->canAccessBusiness($businessId))) {
            return ApiResponse::success([], 'No active business selected or unauthorized.');
        }

        // 1. Invoices & Sales
        $invoicesQuery = DB::table('invoices')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);

        if ($outletId && $outletId !== 'ALL') {
            $invoicesQuery->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }

        $allInvoices = $invoicesQuery->get();
        $salesTotalRupees = 0.0;
        $salesDueTotalRupees = 0.0;
        $salesDueCount = 0;
        $todayStart = date('Y-m-d 00:00:00');
        $todaySalesTotalRupees = 0.0;

        // Daily map for last 7 days
        $weeklyMap = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $weeklyMap[$d] = 0.0;
        }

        $recentSales = [];
        $sortedInvoices = $allInvoices->sortByDesc('created_at')->values();

        // Product ranking accumulators
        $productQuantities = [];
        $productRevenues = [];
        $productSkus = [];
        $productUnits = [];

        foreach ($allInvoices as $inv) {
            $p = json_decode($inv->payload ?? '{}', true) ?: [];
            $fields = $p['fields'] ?? [];
            $status = strtoupper((string) ($fields['status'] ?? $inv->status ?? 'PAID'));
            if ($status === 'CANCELLED') {
                continue;
            }

            $totPaise = (float) ($fields['grandTotalPaise'] ?? $fields['totalAmount'] ?? $fields['grandTotal'] ?? $fields['total'] ?? $inv->total_amount ?? 0);
            $balPaise = (float) ($fields['dueAmount'] ?? $fields['duePaise'] ?? $fields['balanceDue'] ?? $inv->balance_due ?? 0);
            $createdAt = $inv->created_at ? (string) $inv->created_at : '';

            $totRupees = $totPaise >= 100 ? ($totPaise / 100.0) : $totPaise;
            $balRupees = $balPaise >= 100 ? ($balPaise / 100.0) : $balPaise;

            $salesTotalRupees += $totRupees;
            if ($balRupees > 0) {
                $salesDueTotalRupees += $balRupees;
                $salesDueCount++;
            }

            if ($createdAt >= $todayStart) {
                $todaySalesTotalRupees += $totRupees;
            }

            $dateKey = substr($createdAt, 0, 10);
            if (isset($weeklyMap[$dateKey])) {
                $weeklyMap[$dateKey] += $totRupees;
            }

            // Line items aggregation for top/least selling products
            $items = $fields['items'] ?? [];
            foreach ($items as $item) {
                $name = trim((string) ($item['productName'] ?? $item['name'] ?? $item['variantName'] ?? ''));
                if (! $name) {
                    continue;
                }
                $qty = (float) ($item['quantity'] ?? 1);
                $lineTotalPaise = (float) ($item['lineTotalPaise'] ?? $item['totalPaise'] ?? $item['taxablePaise'] ?? $item['lineTotal'] ?? $item['totalAmount'] ?? 0);
                $lineRevRupees = $lineTotalPaise >= 100 ? ($lineTotalPaise / 100.0) : $lineTotalPaise;

                $productQuantities[$name] = ($productQuantities[$name] ?? 0.0) + $qty;
                $productRevenues[$name] = ($productRevenues[$name] ?? 0.0) + $lineRevRupees;

                if (! empty($item['sku'])) {
                    $productSkus[$name] = (string) $item['sku'];
                }
                if (! empty($item['unit'])) {
                    $productUnits[$name] = (string) $item['unit'];
                } elseif (! empty($item['unitId'])) {
                    $productUnits[$name] = (string) $item['unitId'];
                }
            }
        }

        // Top 10 recent sales
        foreach ($sortedInvoices->take(10) as $inv) {
            $p = json_decode($inv->payload ?? '{}', true) ?: [];
            $fields = $p['fields'] ?? [];
            $totPaise = (float) ($fields['grandTotalPaise'] ?? $fields['totalAmount'] ?? $fields['grandTotal'] ?? $fields['total'] ?? $inv->total_amount ?? 0);
            $balPaise = (float) ($fields['dueAmount'] ?? $fields['duePaise'] ?? $fields['balanceDue'] ?? $inv->balance_due ?? 0);
            $totRupees = $totPaise >= 100 ? ($totPaise / 100.0) : $totPaise;
            $rawStatus = strtoupper((string) ($fields['status'] ?? $inv->status ?? 'PAID'));
            $paymentStatus = $rawStatus === 'CANCELLED'
                ? 'CANCELLED'
                : (string) ($fields['paymentStatus'] ?? ($balPaise > 0 ? 'PARTIAL' : 'PAID'));

            $recentSales[] = [
                'invoiceNumber' => (string) ($fields['invoiceNumber'] ?? $fields['invoice_number'] ?? $inv->invoice_number ?? $inv->id),
                'amount' => round($totRupees, 2),
                'paymentStatus' => $paymentStatus,
                'createdAt' => $inv->created_at ? date('Y-m-d\TH:i:s\Z', strtotime($inv->created_at)) : null,
            ];
        }

        $weeklySales = [];
        foreach ($weeklyMap as $date => $amt) {
            $weeklySales[] = [
                'date' => $date.'T00:00:00.000Z',
                'amount' => round($amt, 2),
            ];
        }

        // Top products by quantity
        $topByQtyMap = $productQuantities;
        arsort($topByQtyMap);
        $topProducts = [];
        foreach (array_slice($topByQtyMap, 0, 20, true) as $name => $qty) {
            $topProducts[] = [
                'name' => $name,
                'quantity' => $qty,
                'revenue' => round($productRevenues[$name] ?? 0.0, 2),
                'sku' => $productSkus[$name] ?? '',
                'unit' => $productUnits[$name] ?? 'PCS',
            ];
        }

        // Top products by revenue
        $topByRevMap = $productRevenues;
        arsort($topByRevMap);
        $topProductsByRevenue = [];
        foreach (array_slice($topByRevMap, 0, 20, true) as $name => $rev) {
            $topProductsByRevenue[] = [
                'name' => $name,
                'quantity' => $productQuantities[$name] ?? 0.0,
                'revenue' => round($rev, 2),
                'sku' => $productSkus[$name] ?? '',
                'unit' => $productUnits[$name] ?? 'PCS',
            ];
        }

        // Least selling products
        $bottomByQtyMap = $productQuantities;
        asort($bottomByQtyMap);
        $bottomProducts = [];
        foreach (array_slice($bottomByQtyMap, 0, 20, true) as $name => $qty) {
            $bottomProducts[] = [
                'name' => $name,
                'quantity' => $qty,
                'revenue' => round($productRevenues[$name] ?? 0.0, 2),
                'sku' => $productSkus[$name] ?? '',
                'unit' => $productUnits[$name] ?? 'PCS',
            ];
        }

        // 2. Purchases
        $purchasesQuery = DB::table('purchase_bills')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);

        if ($outletId && $outletId !== 'ALL') {
            $purchasesQuery->where(function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }

        $allPurchases = $purchasesQuery->get();
        $purchaseTotalRupees = 0.0;
        $purchaseDueTotalRupees = 0.0;

        foreach ($allPurchases as $pb) {
            $p = json_decode($pb->payload ?? '{}', true) ?: [];
            $fields = $p['fields'] ?? [];
            $totPaise = (float) ($fields['grandTotal'] ?? $fields['totalAmount'] ?? $fields['subtotal'] ?? $pb->total_amount ?? 0);
            $balPaise = (float) ($fields['outstandingAmount'] ?? $fields['balanceDue'] ?? $pb->balance_due ?? 0);
            $totRupees = $totPaise >= 100 ? ($totPaise / 100.0) : $totPaise;
            $balRupees = $balPaise >= 100 ? ($balPaise / 100.0) : $balPaise;

            $purchaseTotalRupees += $totRupees;
            if ($balRupees > 0) {
                $purchaseDueTotalRupees += $balRupees;
            }
        }

        // 3. Customers & Receivables
        $custQuery = DB::table('customers')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);
        $customerCount = $custQuery->count();

        $custBalanceQuery = DB::table('customer_balances')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);
        $customerDueTotalRupees = 0.0;
        $customersWithDueCount = 0;

        foreach ($custBalanceQuery->get() as $cb) {
            $p = json_decode($cb->payload ?? '{}', true) ?: [];
            $duePaise = (int) ($p['fields']['duePaise'] ?? 0);
            if ($duePaise > 0) {
                $customerDueTotalRupees += ($duePaise / 100.0);
                $customersWithDueCount++;
            }
        }

        // 4. Products & Stock
        $outletProductIds = null;
        if ($outletId && $outletId !== 'ALL') {
            $placementRows = DB::table('outlet_products')
                ->where('business_id', $businessId)
                ->where('outlet_id', $outletId)
                ->where('is_deleted', 0)
                ->get();

            $outletProductIds = [];
            foreach ($placementRows as $pRow) {
                if (strtoupper((string) ($pRow->status ?? 'ACTIVE')) === 'INACTIVE') {
                    continue;
                }
                $pPayload = json_decode($pRow->payload ?? '{}', true) ?: [];
                $pFields = $pPayload['fields'] ?? [];
                if (($pFields['isActive'] ?? true) === false || strtoupper((string) ($pFields['status'] ?? 'ACTIVE')) === 'INACTIVE') {
                    continue;
                }
                $prodId = (string) ($pFields['productId'] ?? $pRow->product_id ?? '');
                if ($prodId) {
                    $outletProductIds[$prodId] = true;
                }
            }
        }

        // Fetch stock balances for this outlet if available
        $stockMap = [];
        $balanceQuery = DB::table('stock_balances')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);
        if ($outletId && $outletId !== 'ALL') {
            $balanceQuery->where('outlet_id', $outletId);
        }
        foreach ($balanceQuery->get() as $bRow) {
            $bPayload = json_decode($bRow->payload ?? '{}', true) ?: [];
            $bFields = $bPayload['fields'] ?? [];
            $vId = (string) ($bFields['variantId'] ?? '');
            $pId = (string) ($bFields['productId'] ?? '');
            $qty = (int) ($bFields['quantityOnHand'] ?? $bFields['currentStock'] ?? 0);
            $stockQty = $qty >= 1000 ? (int) ($qty / 1000) : $qty;
            if ($vId) {
                $stockMap[$vId] = ($stockMap[$vId] ?? 0) + $stockQty;
            }
            if ($pId) {
                $stockMap[$pId] = ($stockMap[$pId] ?? 0) + $stockQty;
            }
        }

        $prodQuery = DB::table('products')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0);
        $allProducts = $prodQuery->get();

        $productCount = 0;
        $activeProductCount = 0;
        $inactiveProductCount = 0;
        $outOfStockCount = 0;
        $lowStockCount = 0;
        $lowStockProducts = [];

        foreach ($allProducts as $pr) {
            $prodId = (string) ($pr->id);
            $pPayload = json_decode($pr->payload ?? '{}', true) ?: [];
            $pFields = $pPayload['fields'] ?? [];
            $altProdId = (string) ($pFields['productId'] ?? '');

            if ($outletProductIds !== null && ! empty($outletProductIds)) {
                if (! isset($outletProductIds[$prodId]) && (! $altProdId || ! isset($outletProductIds[$altProdId]))) {
                    continue;
                }
            }

            $productCount++;
            $st = strtoupper((string) ($pFields['status'] ?? $pr->status ?? 'ACTIVE'));
            if ($st === 'ACTIVE') {
                $activeProductCount++;
            } else {
                $inactiveProductCount++;
            }

            $currentStock = $stockMap[$prodId] ?? ($altProdId ? ($stockMap[$altProdId] ?? null) : null) ?? 0;
            $reorderLevel = (int) ($pFields['reorderLevel'] ?? 5);

            $prodName = (string) ($pFields['productName'] ?? $pFields['name'] ?? 'Product');
            $prodSku = (string) ($pFields['sku'] ?? '');
            $prodUnit = (string) ($pFields['unit'] ?? 'PCS');

            if ($currentStock <= 0) {
                $outOfStockCount++;
                $lowStockCount++;
                if (count($lowStockProducts) < 10) {
                    $lowStockProducts[] = [
                        'variantId' => (string) ($altProdId ?: $prodId),
                        'name' => $prodName,
                        'sku' => $prodSku,
                        'unit' => $prodUnit,
                        'currentStock' => $currentStock,
                        'reorderLevel' => $reorderLevel,
                        'isOutOfStock' => true,
                    ];
                }
            } elseif ($currentStock <= $reorderLevel) {
                $lowStockCount++;
                if (count($lowStockProducts) < 10) {
                    $lowStockProducts[] = [
                        'variantId' => (string) ($altProdId ?: $prodId),
                        'name' => $prodName,
                        'sku' => $prodSku,
                        'unit' => $prodUnit,
                        'currentStock' => $currentStock,
                        'reorderLevel' => $reorderLevel,
                        'isOutOfStock' => false,
                    ];
                }
            }
        }

        // 5. Today Expenses
        $todayExpensesQuery = DB::table('expenses')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->where('status', 'ACTIVE')
            ->where('expense_date', '>=', $todayStart);
        $todayExpensePaise = $todayExpensesQuery->sum('amount_paise');

        $summary = [
            'salesTotal' => round($salesTotalRupees, 2),
            'salesCount' => $allInvoices->count(),
            'purchaseTotal' => round($purchaseTotalRupees, 2),
            'purchaseCount' => $allPurchases->count(),
            'productCount' => $productCount,
            'customerCount' => $customerCount,
            'weeklySales' => $weeklySales,
            'recentSales' => $recentSales,
            'topProducts' => $topProducts,
            'topProductsByRevenue' => $topProductsByRevenue,
            'bottomProducts' => $bottomProducts,
            'salesDueTotal' => round($salesDueTotalRupees, 2),
            'salesDueCount' => $salesDueCount,
            'purchaseDueTotal' => round($purchaseDueTotalRupees, 2),
            'activeProductCount' => $activeProductCount,
            'inactiveProductCount' => $inactiveProductCount,
            'outOfStockCount' => $outOfStockCount,
            'lowStockCount' => $lowStockCount,
            'lowStockProducts' => $lowStockProducts,
            'customerDueTotal' => round($customerDueTotalRupees, 2),
            'customersWithDueCount' => $customersWithDueCount,
            'todaySalesTotal' => round($todaySalesTotalRupees, 2),
            'todayExpenseTotal' => round($todayExpensePaise / 100, 2),
        ];

        if (strtoupper((string) $request->user()->role) === 'STAFF') {
            $salesAllowed = $permissions->isAuthorized($request->user(), ['sales', 'sales.view', 'billing']);
            $purchasesAllowed = $permissions->isAuthorized($request->user(), ['purchase', 'purchase.view']);
            $inventoryAllowed = $permissions->isAuthorized($request->user(), ['inventory', 'inventory.view', 'products', 'products.view', 'product.read', 'billing']);
            $customersAllowed = $permissions->isAuthorized($request->user(), ['customers', 'customers.view', 'CUSTOMER_VIEW', 'billing']);
            $expensesAllowed = $permissions->isAuthorized($request->user(), ['expenses', 'expenses.view']);

            if (! $salesAllowed) {
                $summary = array_replace($summary, [
                    'salesTotal' => 0, 'salesCount' => 0, 'weeklySales' => [], 'recentSales' => [],
                    'topProducts' => [], 'topProductsByRevenue' => [], 'bottomProducts' => [],
                    'salesDueTotal' => 0, 'salesDueCount' => 0, 'todaySalesTotal' => 0,
                ]);
            }
            if (! $purchasesAllowed) {
                $summary = array_replace($summary, ['purchaseTotal' => 0, 'purchaseCount' => 0, 'purchaseDueTotal' => 0]);
            }
            if (! $inventoryAllowed) {
                $summary = array_replace($summary, [
                    'productCount' => 0, 'activeProductCount' => 0, 'inactiveProductCount' => 0,
                    'outOfStockCount' => 0, 'lowStockCount' => 0, 'lowStockProducts' => [],
                ]);
            }
            if (! $customersAllowed) {
                $summary = array_replace($summary, ['customerCount' => 0, 'customerDueTotal' => 0, 'customersWithDueCount' => 0]);
            }
            if (! $expensesAllowed) {
                $summary['todayExpenseTotal'] = 0;
            }
        }

        return ApiResponse::success($summary, 'Dashboard metrics retrieved successfully.');
    }
}
