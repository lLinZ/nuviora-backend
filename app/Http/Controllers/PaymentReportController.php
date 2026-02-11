<?php

namespace App\Http\Controllers;

use App\Models\OrderPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PaymentReportController extends Controller
{
    /**
     * Get payment methods summary for a given date range
     * Returns total amount collected per payment method
     */
    public function paymentsByMethod(Request $request)
    {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        $dateFrom = $request->filled('date_from') 
            ? Carbon::parse($request->date_from)->startOfDay() 
            : Carbon::today()->startOfDay();
        
        $dateTo = $request->filled('date_to') 
            ? Carbon::parse($request->date_to)->endOfDay() 
            : Carbon::today()->endOfDay();

        // Get all payments grouped by method within date range
        $paymentsData = OrderPayment::whereBetween('created_at', [$dateFrom, $dateTo])
            ->select(
                'method',
                DB::raw('COUNT(*) as transaction_count'),
                DB::raw('SUM(amount) as total_amount')
            )
            ->groupBy('method')
            ->get();

        // Format the response
        $summary = $paymentsData->mapWithKeys(function ($item) {
            return [
                $item->method => [
                    'method' => $item->method,
                    'transaction_count' => (int) $item->transaction_count,
                    'total_amount' => (float) $item->total_amount
                ]
            ];
        });

        // Calculate grand total
        $grandTotal = $paymentsData->sum('total_amount');
        $totalTransactions = $paymentsData->sum('transaction_count');

        return response()->json([
            'status' => true,
            'data' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'methods' => $summary,
                'totals' => [
                    'grand_total' => (float) $grandTotal,
                    'total_transactions' => (int) $totalTransactions
                ]
            ]
        ]);
    }
}
