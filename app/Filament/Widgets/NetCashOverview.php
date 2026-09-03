<?php

namespace App\Filament\Widgets;

use App\Enums\AccountType;
use App\Models\Account;
use App\Support\CurrentOwner;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

class NetCashOverview extends StatsOverviewWidget implements HasActions
{
    use InteractsWithActions;

    /**
     * @var view-string
     */
    protected string $view = 'filament.widgets.net-cash-overview';

    protected static ?int $sort = -2; // above SyncStatusOverview (undeclared => -1)

    protected ?string $heading = 'Net cash';

    protected ?string $pollingInterval = null; // CanPoll defaults to '5s'; balances only move on sync

    private ?Collection $ownedAccounts = null;

    protected function getStats(): array
    {
        $totals = $this->aggregateTotals();

        return [
            Stat::make('Net cash', $this->formatCents($totals['netCents']))
                ->description($this->netCashDescription($totals))
                ->descriptionIcon($totals['unclassifiedCount'] > 0 ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedClock)
                ->color($totals['netCents'] >= 0 ? 'success' : 'danger'),
            Stat::make('Liquid net cash', $this->formatCents($totals['liquidNetCents']))
                ->description($this->liquidNetCashDescription($totals))
                ->descriptionIcon(Heroicon::OutlinedWallet)
                ->color($totals['liquidNetCents'] >= 0 ? 'success' : 'danger'),
            Stat::make('Assets', $this->formatCents($totals['assetCents']))
                ->description("{$totals['assetCount']} checking/savings")
                ->color('success'),
            Stat::make('Debts', $this->formatCents(-$totals['debtCents']))
                ->description("{$totals['debtCount']} credit/loan")
                ->color('danger'),
        ];
    }

    public function getSectionContentComponent(): Component
    {
        $section = parent::getSectionContentComponent();

        // Parent's return type is the broad Component; headerActions() is Section API.
        return $section instanceof Section
            ? $section->headerActions([$this->configureAction()])
            : $section;
    }

    public function configureAction(): Action
    {
        return Action::make('configure')
            ->label('Configure')
            ->schema(fn (): array => $this->ownedAccounts()
                ->map(fn (Account $account): Component => Grid::make(2)
                    ->schema([
                        Select::make("accounts.{$account->id}.account_type")
                            ->label($account->display_name)
                            ->options(array_combine(
                                array_map(fn (AccountType $case): string => $case->value, AccountType::cases()),
                                array_map(fn (AccountType $case): string => $case->label(), AccountType::cases()),
                            ))
                            ->native(false)
                            ->placeholder('Not set'),
                        Toggle::make("accounts.{$account->id}.include")
                            ->label('Include in net cash')
                            ->inline(false),
                    ]))
                ->all())
            ->fillForm(fn (): array => [
                'accounts' => $this->ownedAccounts()
                    ->mapWithKeys(fn (Account $account): array => [
                        $account->id => [
                            'account_type' => $account->account_type?->value,
                            'include' => $account->include_in_net_cash,
                        ],
                    ])
                    ->all(),
            ])
            ->action(function (array $data): void {
                // Driving the loop from the owner-scoped collection, rather than the
                // submitted keys, is the authorization boundary: a tampered Livewire
                // payload naming a stranger's account id is simply never visited.
                $this->ownedAccounts()->each(function (Account $account) use ($data): void {
                    $submitted = $data['accounts'][$account->id] ?? null;

                    if ($submitted === null) {
                        return;
                    }

                    $rawType = $submitted['account_type'] ?? null;
                    $accountType = filled($rawType) ? AccountType::tryFrom($rawType) : null;

                    // A non-blank type that doesn't resolve to a known case is a
                    // tampered/invalid submission; skip this account rather than
                    // write garbage or silently null out its classification.
                    if (filled($rawType) && $accountType === null) {
                        return;
                    }

                    $account->update([
                        'account_type' => $accountType,
                        'include_in_net_cash' => (bool) ($submitted['include'] ?? true),
                    ]);
                });

                $this->cachedStats = null;
                $this->ownedAccounts = null;

                // Filament's action response only partially re-renders the
                // schema (the modal), not the surrounding widget's stats —
                // without this, the tiles show stale totals until the next
                // full page load.
                $this->dispatch('$refresh');

                Notification::make()
                    ->title('Accounts updated')
                    ->success()
                    ->send();
            });
    }

    /**
     * Owner-scoped query, same shape as ImportTransactions::form(). Memoized;
     * one query serves the stats, the unclassified count, asOf, and the
     * Configure modal.
     *
     * @return Collection<int, Account>
     */
    private function ownedAccounts(): Collection
    {
        return $this->ownedAccounts ??= Account::query()
            ->select(['id', 'name', 'institution_id', 'account_type', 'current_balance', 'balances_updated_at', 'include_in_net_cash'])
            ->whereHas('connection', fn (Builder $query) => $query->where('user_id', CurrentOwner::id()))
            ->with('institution:id,name') // display_name accessor needs it
            ->orderBy('name')
            ->get();
    }

    /**
     * Aggregates in PHP, in integer cents. A SQL CASE would restate the
     * enum->sign mapping as a hard-coded IN (...) list, duplicating
     * AccountType::netCashSign() and losing exhaustiveness checking.
     *
     * @return array{netCents: int, liquidNetCents: int, assetCents: int, debtCents: int, loanCents: int, assetCount: int, debtCount: int, unclassifiedCount: int, asOf: ?Carbon}
     */
    private function aggregateTotals(): array
    {
        $assetCents = 0;
        $debtCents = 0;
        $liquidNetCents = 0;
        $loanCents = 0;
        $assetCount = 0;
        $debtCount = 0;
        $unclassifiedCount = 0;
        $asOf = null;

        foreach ($this->ownedAccounts() as $account) {
            if ($account->include_in_net_cash === false) {
                continue;
            }

            if ($account->account_type === null) {
                $unclassifiedCount++;

                continue;
            }

            $sign = $account->account_type->netCashSign();

            if ($sign === 0) {
                continue;
            }

            // decimal:2 casts current_balance to a string; never accumulate
            // as floats. abs() is where the sign coercion happens — the
            // provider's sign is discarded and netCashSign() decides.
            $cents = abs((int) round(((float) ($account->current_balance ?? '0')) * 100));

            if ($sign > 0) {
                $assetCents += $cents;
                $assetCount++;
            } else {
                $debtCents += $cents;
                $debtCount++;
            }

            $liquidSign = $account->account_type->liquidNetCashSign();
            $liquidNetCents += $liquidSign * $cents;

            // Debt the liquid total deliberately excludes (Loan). Derived from the
            // enum's two signs rather than naming Loan here, so classification
            // stays in one place — see aggregateTotals()'s docblock.
            if ($liquidSign === 0) {
                $loanCents += $cents;
            }

            if ($account->balances_updated_at !== null
                && ($asOf === null || $account->balances_updated_at->gt($asOf))) {
                $asOf = $account->balances_updated_at;
            }
        }

        return [
            'netCents' => $assetCents - $debtCents,
            'liquidNetCents' => $liquidNetCents,
            'assetCents' => $assetCents,
            'debtCents' => $debtCents,
            'loanCents' => $loanCents,
            'assetCount' => $assetCount,
            'debtCount' => $debtCount,
            'unclassifiedCount' => $unclassifiedCount,
            'asOf' => $asOf,
        ];
    }

    /**
     * @param  array{netCents: int, liquidNetCents: int, assetCents: int, debtCents: int, loanCents: int, assetCount: int, debtCount: int, unclassifiedCount: int, asOf: ?Carbon}  $totals
     */
    private function netCashDescription(array $totals): string
    {
        if ($totals['unclassifiedCount'] > 0) {
            return "{$totals['unclassifiedCount']} accounts not classified yet";
        }

        if ($totals['asOf'] !== null) {
            return "As of {$totals['asOf']->diffForHumans()}";
        }

        return 'No balances synced yet';
    }

    /**
     * @param  array{netCents: int, liquidNetCents: int, assetCents: int, debtCents: int, loanCents: int, assetCount: int, debtCount: int, unclassifiedCount: int, asOf: ?Carbon}  $totals
     */
    private function liquidNetCashDescription(array $totals): string
    {
        if ($totals['loanCents'] > 0) {
            return 'Excludes '.$this->formatCents($totals['loanCents']).' loan debt';
        }

        return 'Assets minus card debt';
    }

    private function formatCents(int $cents): string
    {
        return Number::currency($cents / 100, 'USD');
    }
}
