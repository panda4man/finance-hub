<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\InteractsWithTransactionFilters;
use App\Services\TransactionQueryService;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('spending_trend')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[Description(<<<'MARKDOWN'
    Returns a gap-filled time series of totals for a merchant or category over time,
    plus summary statistics. Returns data, not an image — the client should render
    the chart. Requires merchant or categories (a trend of literally everything is
    not useful).

    Example: "Trend of my Duke Energy bill over the last few years" ->
    search_merchants{query:"Duke Energy"} then
    spending_trend{merchant:"duke energy", granularity:"month", date_from:"<3y ago>"}.
    MARKDOWN
)]
class SpendingTrend extends Tool
{
    use InteractsWithTransactionFilters;

    private const MAX_PERIODS = 120;

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->resolveUser($request);

        $rules = $this->transactionFilterRules();
        $rules['granularity'] = ['nullable', 'in:month,quarter,year'];
        $rules['merchant'][] = 'required_without:categories';
        $rules['categories'][] = 'required_without:merchant';

        $validated = $request->validate($rules);

        $validated['direction'] ??= 'outflow';
        $granularity = $validated['granularity'] ?? 'month';

        $today = CarbonImmutable::today();

        if (empty($validated['date_to'])) {
            $validated['date_to'] = $today->toDateString();
        }

        if (empty($validated['date_from'])) {
            $from = $today->subYears(3);
            $validated['date_from'] = match ($granularity) {
                'quarter' => $from->startOfQuarter()->toDateString(),
                'year' => $from->startOfYear()->toDateString(),
                default => $from->startOfMonth()->toDateString(),
            };
        }

        $filters = $this->transactionFiltersFrom($validated);

        $fromDate = CarbonImmutable::parse($validated['date_from']);
        $toDate = CarbonImmutable::parse($validated['date_to']);

        [$periodStart, $interval] = match ($granularity) {
            'quarter' => [$fromDate->startOfQuarter(), '3 months'],
            'year' => [$fromDate->startOfYear(), '1 year'],
            default => [$fromDate->startOfMonth(), '1 month'],
        };

        $periodEndBound = match ($granularity) {
            'quarter' => $toDate->startOfQuarter(),
            'year' => $toDate->startOfYear(),
            default => $toDate->startOfMonth(),
        };

        $period = CarbonPeriod::create($periodStart, $interval, $periodEndBound);
        $periodCount = count($period);

        if ($periodCount > self::MAX_PERIODS) {
            throw ValidationException::withMessages([
                'date_from' => "The range spans {$periodCount} {$granularity}s; at most ".self::MAX_PERIODS.' periods are allowed. Narrow the range or use a coarser granularity.',
            ]);
        }

        $service = app(TransactionQueryService::class);
        $query = $service->applyFilters($service->ownedQuery($user), $filters);

        $rows = $query->toBase()->select([])
            ->selectRaw('date_trunc(?, transactions.date)::date AS period_start', [$granularity])
            ->selectRaw('SUM(transactions.amount) AS total')
            ->selectRaw('COUNT(*) AS txn_count')
            ->groupBy('period_start')
            ->orderBy('period_start')
            ->get()
            ->keyBy(fn ($row) => (string) $row->period_start);

        $series = [];

        foreach ($period as $periodDate) {
            // Rebuild an immutable instance from the key rather than chaining off
            // $periodDate: CarbonPeriod iteration values are mutable Carbon
            // instances, so addMonth()/subDay() below would otherwise mutate
            // $periodDate in place and corrupt the is_partial comparison.
            $key = $periodDate->toDateString();
            $periodStartImmutable = CarbonImmutable::parse($key);
            $row = $rows->get($key);

            $periodEndInclusive = match ($granularity) {
                'quarter' => $periodStartImmutable->addMonths(3)->subDay(),
                'year' => $periodStartImmutable->addYear()->subDay(),
                default => $periodStartImmutable->addMonth()->subDay(),
            };

            $series[] = [
                'period_start' => $key,
                'label' => match ($granularity) {
                    'quarter' => $periodStartImmutable->format('Y').'-Q'.$periodStartImmutable->quarter,
                    'year' => $periodStartImmutable->format('Y'),
                    default => $periodStartImmutable->format('Y-m'),
                },
                'total' => $this->money($row->total ?? 0),
                'count' => (int) ($row->txn_count ?? 0),
                'is_partial' => $periodStartImmutable->lt($fromDate) || $periodEndInclusive->gt($toDate),
            ];
        }

        return Response::structured([
            'applied_filters' => [
                ...$this->appliedFilters($filters),
                'granularity' => $granularity,
            ],
            'granularity' => $granularity,
            'series' => $series,
            'summary' => $this->summarize($series),
            'chart_hint' => ['type' => 'line', 'x' => 'label', 'y' => 'total'],
        ]);
    }

    /**
     * @param  list<array{period_start: string, label: string, total: string, count: int, is_partial: bool}>  $series
     * @return array<string, mixed>
     */
    private function summarize(array $series): array
    {
        $totalSum = array_sum(array_map(fn ($p) => (float) $p['total'], $series));
        $periodsWithActivity = count(array_filter($series, fn ($p) => $p['count'] > 0));

        $minEntry = null;
        $maxEntry = null;

        foreach ($series as $p) {
            $value = (float) $p['total'];

            if ($minEntry === null || $value < (float) $minEntry['total']) {
                $minEntry = $p;
            }

            if ($maxEntry === null || $value > (float) $maxEntry['total']) {
                $maxEntry = $p;
            }
        }

        $firstTotal = (float) $series[0]['total'];
        $lastTotal = (float) $series[count($series) - 1]['total'];
        $change = $lastTotal - $firstTotal;

        return [
            'period_count' => count($series),
            'periods_with_activity' => $periodsWithActivity,
            'total' => $this->money($totalSum),
            'mean' => $this->money($totalSum / max(count($series), 1)),
            'min' => ['label' => $minEntry['label'], 'total' => $minEntry['total']],
            'max' => ['label' => $maxEntry['label'], 'total' => $maxEntry['total']],
            'first_period_total' => $series[0]['total'],
            'last_period_total' => $series[count($series) - 1]['total'],
            'change' => $this->money($change),
            'change_percent' => $firstTotal !== 0.0 ? round(($change / abs($firstTotal)) * 100, 1) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->transactionFilterSchema($schema),
            'granularity' => $schema->string()->enum(['month', 'quarter', 'year'])
                ->description('Bucket size for the trend. Default month.'),
        ];
    }
}
