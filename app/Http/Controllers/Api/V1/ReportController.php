<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * Overview KPI cards & trend for selected date range.
     */
    public function overview(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $outletId = $request->query('outlet_id') ?? $request->query('outletId') ?? $request->header('X-Outlet-Id');
        $startDate = $request->query('start_date') ?? $request->query('startDate') ?? date('Y-m-01');
        $endDate = $request->query('end_date') ?? $request->query('endDate') ?? date('Y-m-d');

        if (!$businessId) {
            $firstBus = DB::table('businesses')->where('is_deleted', 0)->first();
            $businessId = $firstBus ? (string) ($firstBus->business_id ?? $firstBus->id) : null;
        }

        if (!$businessId) {
            return ApiResponse::success([], 'No active business selected.');
        }

        $startDt = date('Y-m-d 00:00:00', strtotime($startDate));
        $endDt = date('Y-m-d 23:59:59', strtotime($endDate));

        // Invoices in range
        $invQuery = DB::table('invoices')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->whereBetween('created_at', [$startDt, $endDt]);
        if ($outletId && $outletId !== 'ALL') {
            $invQuery->where('outlet_id', $outletId);
        }
        $invoices = $invQuery->get();

        $grossSalesPaise = 0;
        $totalTaxPaise = 0;
        $totalDiscountPaise = 0;

        foreach ($invoices as $inv) {
            $p = json_decode($inv->payload ?? '{}', true) ?: [];
            $fields = $p['fields'] ?? [];
            $grossSalesPaise += (int) (($fields['subtotal'] ?? $fields['totalAmount'] ?? $inv->total_amount ?? 0) * 100);
            $totalTaxPaise += (int) (($fields['taxAmount'] ?? $inv->tax_amount ?? 0) * 100);
            $totalDiscountPaise += (int) (($fields['discountAmount'] ?? $inv->discount_amount ?? 0) * 100);
        }
        $netSalesPaise = $grossSalesPaise + $totalTaxPaise - $totalDiscountPaise;

        // Purchases in range
        $purQuery = DB::table('purchase_bills')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->whereBetween('created_at', [$startDt, $endDt]);
        if ($outletId && $outletId !== 'ALL') {
            $purQuery->where('outlet_id', $outletId);
        }
        $purchases = $purQuery->get();
        $purchasesTotalPaise = 0;
        foreach ($purchases as $pb) {
            $p = json_decode($pb->payload ?? '{}', true) ?: [];
            $fields = $p['fields'] ?? [];
            $purchasesTotalPaise += (int) (($fields['totalAmount'] ?? $pb->total_amount ?? 0) * 100);
        }

        // Expenses in range
        $expQuery = DB::table('expenses')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->where('status', 'ACTIVE')
            ->whereBetween('expense_date', [$startDt, $endDt]);
        if ($outletId && $outletId !== 'ALL') {
            $expQuery->where('outlet_id', $outletId);
        }
        $expensesTotalPaise = (int) $expQuery->sum('amount_paise');

        $profitEstimatePaise = $netSalesPaise - $purchasesTotalPaise - $expensesTotalPaise;

        $data = [
            'grossSalesPaise' => $grossSalesPaise,
            'netSalesPaise' => $netSalesPaise,
            'totalTaxPaise' => $totalTaxPaise,
            'totalDiscountPaise' => $totalDiscountPaise,
            'invoiceCount' => $invoices->count(),
            'purchasesTotalPaise' => $purchasesTotalPaise,
            'purchasesCount' => $purchases->count(),
            'expensesTotalPaise' => $expensesTotalPaise,
            'expensesCount' => $expQuery->count(),
            'profitEstimatePaise' => $profitEstimatePaise,
            'profitEstimateRupees' => round($profitEstimatePaise / 100, 2),
            'startDate' => $startDate,
            'endDate' => $endDate,
        ];

        return ApiResponse::success($data, 'Reports overview calculated successfully.');
    }

    /**
     * Sales Register & summary report.
     */
    public function sales(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $outletId = $request->query('outlet_id') ?? $request->query('outletId') ?? $request->header('X-Outlet-Id');
        $startDate = $request->query('start_date') ?? $request->query('startDate') ?? date('Y-m-01');
        $endDate = $request->query('end_date') ?? $request->query('endDate') ?? date('Y-m-d');

        $startDt = date('Y-m-d 00:00:00', strtotime($startDate));
        $endDt = date('Y-m-d 23:59:59', strtotime($endDate));

        $query = DB::table('invoices')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->whereBetween('created_at', [$startDt, $endDt]);

        if ($outletId && $outletId !== 'ALL') {
            $query->where('outlet_id', $outletId);
        }

        $invoices = $query->orderBy('created_at', 'desc')->get();

        $rows = $invoices->map(function ($inv) {
            $p = json_decode($inv->payload ?? '{}', true) ?: [];
            $fields = $p['fields'] ?? [];
            return [
                'invoiceId' => (string) $inv->id,
                'invoiceNumber' => (string) ($fields['invoiceNumber'] ?? $inv->invoice_number ?? $inv->id),
                'customerName' => (string) ($fields['customerName'] ?? $inv->customer_name ?? 'Cash Customer'),
                'totalAmount' => (float) ($fields['total'] ?? $inv->total_amount ?? 0),
                'taxAmount' => (float) ($fields['taxAmount'] ?? $inv->tax_amount ?? 0),
                'balanceDue' => (float) ($fields['balanceDue'] ?? $inv->balance_due ?? 0),
                'paymentMode' => (string) ($fields['paymentMode'] ?? $inv->payment_mode ?? 'CASH'),
                'paymentStatus' => (string) ($fields['paymentStatus'] ?? $inv->payment_status ?? 'PAID'),
                'createdAt' => $inv->created_at ? date('Y-m-d\TH:i:s\Z', strtotime($inv->created_at)) : null,
            ];
        });

        return ApiResponse::success([
            'totalInvoices' => $rows->count(),
            'totalSalesRupees' => round($rows->sum('totalAmount'), 2),
            'totalTaxRupees' => round($rows->sum('taxAmount'), 2),
            'invoices' => $rows,
        ], 'Sales report generated.');
    }

    /**
     * Purchase summary report.
     */
    public function purchases(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $outletId = $request->query('outlet_id') ?? $request->query('outletId') ?? $request->header('X-Outlet-Id');
        $startDate = $request->query('start_date') ?? $request->query('startDate') ?? date('Y-m-01');
        $endDate = $request->query('end_date') ?? $request->query('endDate') ?? date('Y-m-d');

        $startDt = date('Y-m-d 00:00:00', strtotime($startDate));
        $endDt = date('Y-m-d 23:59:59', strtotime($endDate));

        $query = DB::table('purchase_bills')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->whereBetween('created_at', [$startDt, $endDt]);

        if ($outletId && $outletId !== 'ALL') {
            $query->where('outlet_id', $outletId);
        }

        $purchases = $query->orderBy('created_at', 'desc')->get();

        $rows = $purchases->map(function ($pb) {
            $p = json_decode($pb->payload ?? '{}', true) ?: [];
            $fields = $p['fields'] ?? [];
            return [
                'billId' => (string) $pb->id,
                'billNumber' => (string) ($fields['billNumber'] ?? $pb->bill_number ?? $pb->id),
                'supplierName' => (string) ($fields['supplierName'] ?? $pb->supplier_name ?? 'Supplier'),
                'totalAmount' => (float) ($fields['totalAmount'] ?? $pb->total_amount ?? 0),
                'balanceDue' => (float) ($fields['balanceDue'] ?? $pb->balance_due ?? 0),
                'status' => (string) ($fields['status'] ?? $pb->status ?? 'ACTIVE'),
                'createdAt' => $pb->created_at ? date('Y-m-d\TH:i:s\Z', strtotime($pb->created_at)) : null,
            ];
        });

        return ApiResponse::success([
            'totalBills' => $rows->count(),
            'totalPurchasesRupees' => round($rows->sum('totalAmount'), 2),
            'bills' => $rows,
        ], 'Purchases report generated.');
    }

    /**
     * Inventory valuation & stock health report.
     */
    public function inventory(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');

        $products = DB::table('products')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->get();

        $totalValuationRupees = 0.0;
        $outOfStockItems = [];
        $lowStockItems = [];

        foreach ($products as $pr) {
            $stock = (int) ($pr->current_stock ?? $pr->stock_quantity ?? 0);
            $reorder = (int) ($pr->reorder_level ?? 5);
            $cost = (float) ($pr->cost_price ?? $pr->purchase_price ?? 0);
            $totalValuationRupees += ($stock * $cost);

            if ($stock <= 0) {
                $outOfStockItems[] = [
                    'productId' => (string) $pr->id,
                    'name' => (string) $pr->name,
                    'sku' => (string) ($pr->sku ?? ''),
                    'currentStock' => $stock,
                ];
            } elseif ($stock <= $reorder) {
                $lowStockItems[] = [
                    'productId' => (string) $pr->id,
                    'name' => (string) $pr->name,
                    'sku' => (string) ($pr->sku ?? ''),
                    'currentStock' => $stock,
                    'reorderLevel' => $reorder,
                ];
            }
        }

        return ApiResponse::success([
            'totalProducts' => $products->count(),
            'totalValuationRupees' => round($totalValuationRupees, 2),
            'outOfStockCount' => count($outOfStockItems),
            'lowStockCount' => count($lowStockItems),
            'outOfStockItems' => array_slice($outOfStockItems, 0, 20),
            'lowStockItems' => array_slice($lowStockItems, 0, 20),
        ], 'Inventory valuation report generated.');
    }

    /**
     * Profit & Loss summary report.
     */
    public function profitLoss(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $startDate = $request->query('start_date') ?? $request->query('startDate') ?? date('Y-m-01');
        $endDate = $request->query('end_date') ?? $request->query('endDate') ?? date('Y-m-d');

        $startDt = date('Y-m-d 00:00:00', strtotime($startDate));
        $endDt = date('Y-m-d 23:59:59', strtotime($endDate));

        // Revenue (Sales)
        $salesPaise = 0;
        $invoices = DB::table('invoices')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->whereBetween('created_at', [$startDt, $endDt])
            ->get();
        foreach ($invoices as $inv) {
            $p = json_decode($inv->payload ?? '{}', true) ?: [];
            $fields = $p['fields'] ?? [];
            $salesPaise += (int) (($fields['total'] ?? $inv->total_amount ?? 0) * 100);
        }

        // COGS (Purchases)
        $cogsPaise = 0;
        $purchases = DB::table('purchase_bills')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->whereBetween('created_at', [$startDt, $endDt])
            ->get();
        foreach ($purchases as $pb) {
            $p = json_decode($pb->payload ?? '{}', true) ?: [];
            $fields = $p['fields'] ?? [];
            $cogsPaise += (int) (($fields['totalAmount'] ?? $pb->total_amount ?? 0) * 100);
        }

        // Operating Expenses
        $expenses = DB::table('expenses')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->where('status', 'ACTIVE')
            ->whereBetween('expense_date', [$startDt, $endDt])
            ->get();
        $expensesPaise = (int) $expenses->sum('amount_paise');

        // Category breakdown
        $expensesByCategory = [];
        foreach ($expenses as $exp) {
            $cat = (string) $exp->category;
            $amt = (int) $exp->amount_paise;
            $expensesByCategory[$cat] = ($expensesByCategory[$cat] ?? 0) + $amt;
        }

        $grossProfitPaise = $salesPaise - $cogsPaise;
        $netProfitPaise = $grossProfitPaise - $expensesPaise;

        return ApiResponse::success([
            'salesRevenuePaise' => $salesPaise,
            'salesRevenueRupees' => round($salesPaise / 100, 2),
            'costOfGoodsSoldPaise' => $cogsPaise,
            'costOfGoodsSoldRupees' => round($cogsPaise / 100, 2),
            'grossProfitPaise' => $grossProfitPaise,
            'grossProfitRupees' => round($grossProfitPaise / 100, 2),
            'operatingExpensesPaise' => $expensesPaise,
            'operatingExpensesRupees' => round($expensesPaise / 100, 2),
            'expensesByCategory' => $expensesByCategory,
            'netProfitPaise' => $netProfitPaise,
            'netProfitRupees' => round($netProfitPaise / 100, 2),
            'netMarginPercentage' => $salesPaise > 0 ? round(($netProfitPaise / $salesPaise) * 100, 2) : 0,
        ], 'Profit and Loss statement generated.');
    }

    /**
     * Statutory GST (GSTR-1 & GSTR-3B) report.
     */
    public function tax(Request $request): JsonResponse
    {
        $businessId = $request->query('business_id') ?? $request->query('businessId') ?? $request->header('X-Business-Id');
        $startDate = $request->query('start_date') ?? $request->query('startDate') ?? date('Y-m-01');
        $endDate = $request->query('end_date') ?? $request->query('endDate') ?? date('Y-m-d');

        $startDt = date('Y-m-d 00:00:00', strtotime($startDate));
        $endDt = date('Y-m-d 23:59:59', strtotime($endDate));

        $invoices = DB::table('invoices')
            ->where('business_id', $businessId)
            ->where('is_deleted', 0)
            ->whereBetween('created_at', [$startDt, $endDt])
            ->get();

        $totalTaxableRupees = 0.0;
        $totalCgstRupees = 0.0;
        $totalSgstRupees = 0.0;
        $totalIgstRupees = 0.0;

        foreach ($invoices as $inv) {
            $p = json_decode($inv->payload ?? '{}', true) ?: [];
            $fields = $p['fields'] ?? [];
            $taxable = (float) ($fields['subtotal'] ?? $inv->subtotal ?? 0);
            $tax = (float) ($fields['taxAmount'] ?? $inv->tax_amount ?? 0);
            $totalTaxableRupees += $taxable;
            $totalCgstRupees += ($tax / 2);
            $totalSgstRupees += ($tax / 2);
        }

        return ApiResponse::success([
            'taxableValueRupees' => round($totalTaxableRupees, 2),
            'cgstRupees' => round($totalCgstRupees, 2),
            'sgstRupees' => round($totalSgstRupees, 2),
            'igstRupees' => round($totalIgstRupees, 2),
            'totalTaxRupees' => round($totalCgstRupees + $totalSgstRupees + $totalIgstRupees, 2),
            'invoiceCount' => $invoices->count(),
        ], 'GST summary generated.');
    }
}
