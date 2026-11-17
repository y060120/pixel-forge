<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\User;
use App\Services\PixelforgeClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminReportController extends Controller
{
    /**
     * Operator Admin Report showing credit spend, provider costs, profit, and reconciliation.
     */
    public function index(Request $request, PixelforgeClient $client): Response
    {
        $startDate = $request->query('start_date', now()->subDays(14)->format('Y-m-d'));
        $endDate = $request->query('end_date', now()->format('Y-m-d'));

        // Query daily metrics from completed generations in database
        $dailyRecords = Generation::query()
            ->whereNotNull('completed_at')
            ->whereDate('completed_at', '>=', $startDate)
            ->whereDate('completed_at', '<=', $endDate)
            ->selectRaw("
                DATE(completed_at) as date,
                COUNT(*) as total_count,
                SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) as succeeded_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                COALESCE(SUM(pf_cost), 0) as db_provider_cost,
                COALESCE(SUM(user_cost), 0) as db_user_spend
            ")
            ->groupBy(DB::raw('DATE(completed_at)'))
            ->orderBy('date', 'desc')
            ->get()
            ->keyBy('date');

        // Fetch official Pixelforge provider usage report for reconciliation
        $pfUsage = $client->getUsage($startDate, $endDate);
        $pfDays = collect($pfUsage['data']['data'] ?? [])->keyBy('date');

        // Build continuous date range list
        $periodStart = Carbon::parse($startDate);
        $periodEnd = Carbon::parse($endDate);
        $datesList = [];

        for ($date = $periodEnd->copy(); $date->gte($periodStart); $date->subDay()) {
            $d = $date->format('Y-m-d');
            $dbRow = $dailyRecords->get($d);
            $pfRow = $pfDays->get($d);

            $dbProviderCost = (string) ($dbRow->db_provider_cost ?? '0.000000000');
            $dbUserSpend = (string) ($dbRow->db_user_spend ?? '0.000000000');
            $pfReportedCost = (string) ($pfRow['cost_usd'] ?? '0.000000000');

            $profit = bcsub($dbUserSpend, $dbProviderCost, 9);
            $discrepancy = bcsub($dbProviderCost, $pfReportedCost, 9);

            $datesList[] = [
                'date' => $d,
                'total_generations' => (int) ($dbRow->total_count ?? 0),
                'succeeded_generations' => (int) ($dbRow->succeeded_count ?? 0),
                'failed_generations' => (int) ($dbRow->failed_count ?? 0),
                'pf_reported_generations' => (int) ($pfRow['generations'] ?? 0),
                'user_spend' => $dbUserSpend,
                'provider_cost' => $dbProviderCost,
                'profit' => $profit,
                'pf_reported_cost' => $pfReportedCost,
                'reconciled' => bccomp($discrepancy, '0', 9) === 0,
                'discrepancy' => $discrepancy,
            ];
        }

        // Per-user breakdown
        $userMetrics = User::query()
            ->withCount(['generations as total_generations'])
            ->withCount(['generations as succeeded_generations' => function ($query) {
                $query->where('status', 'succeeded');
            }])
            ->withCount(['generations as failed_generations' => function ($query) {
                $query->where('status', 'failed');
            }])
            ->get()
            ->map(function ($u) {
                $userGenerations = Generation::where('user_id', $u->id)
                    ->where('status', 'succeeded')
                    ->get();

                $spend = '0.000000000';
                $pfCost = '0.000000000';

                foreach ($userGenerations as $g) {
                    $spend = bcadd($spend, (string) ($g->user_cost ?? '0'), 9);
                    $pfCost = bcadd($pfCost, (string) ($g->pf_cost ?? '0'), 9);
                }

                $profit = bcsub($spend, $pfCost, 9);

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'balance' => $u->balance,
                    'total_generations' => $u->total_generations,
                    'succeeded_generations' => $u->succeeded_generations,
                    'failed_generations' => $u->failed_generations,
                    'credit_spend' => $spend,
                    'provider_cost' => $pfCost,
                    'profit' => $profit,
                ];
            });

        // Overall summary totals
        $totalSpend = '0.000000000';
        $totalProviderCost = '0.000000000';
        $totalPfReportedCost = (string) ($pfUsage['data']['total']['cost_usd'] ?? '0.000000000');

        foreach ($datesList as $day) {
            $totalSpend = bcadd($totalSpend, $day['user_spend'], 9);
            $totalProviderCost = bcadd($totalProviderCost, $day['provider_cost'], 9);
        }

        $totalProfit = bcsub($totalSpend, $totalProviderCost, 9);

        return Inertia::render('Admin/Report', [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dailyData' => $datesList,
            'userMetrics' => $userMetrics,
            'totals' => [
                'spend' => $totalSpend,
                'provider_cost' => $totalProviderCost,
                'profit' => $totalProfit,
                'pf_reported_cost' => $totalPfReportedCost,
                'reconciled' => bccomp(bcsub($totalProviderCost, $totalPfReportedCost, 9), '0', 9) === 0,
            ],
            'hasPixelforgeKey' => ! empty(config('services.pixelforge.api_key')),
        ]);
    }
}
