<?php

namespace App\Admin\Widgets;

use App\Enums\InvoiceTransactionStatus;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\Ticket;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class Overview extends BaseWidget
{
    // Poll every 5 minutes (5m doesn't work somehow)
    protected ?string $pollingInterval = '600s';

    protected function getStats(): array
    {
        return [
            $this->invoiceTransaction(),
            $this->getData(Ticket::class, 'Tickets'),
            $this->getData(Service::class, 'Services'),
        ];
    }

    private function invoiceTransaction(): Stat
    {
        $query = InvoiceTransaction::query()
            ->where('status', InvoiceTransactionStatus::Succeeded)
            ->where('is_credit_transaction', false);

        $chart = $this->trend(Trend::query(clone $query))->sum('amount');

        $previous = $this->previousPeriod(clone $query)->sum('amount');

        return $this->stat('Revenue', $chart, $previous);
    }

    private function getData(string $model, string $name): Stat
    {
        $chart = $this->trend(Trend::model($model))->count();

        $previous = $this->previousPeriod($model::query())->count();

        return $this->stat($name, $chart, $previous);
    }

    private function trend(Trend $trend): Trend
    {
        return $trend
            ->between(
                start: now()->subMonth()->startOfDay(),
                end: now(),
            )
            ->perDay();
    }

    /**
     * Scope the query to the period directly before the one shown, without overlapping it.
     */
    private function previousPeriod(Builder $query): Builder
    {
        $start = now()->subMonth()->startOfDay();

        return $query
            ->where('created_at', '>=', $start->copy()->subMonth()->startOfDay())
            ->where('created_at', '<', $start);
    }

    private function stat(string $label, Collection $chart, float|int $previous): Stat
    {
        $current = $chart->sum('aggregate');

        $change = $current - $previous;

        $percentage = $previous > 0 ? (abs($change) / $previous) * 100 : 0;

        return Stat::make($label, $current)
            ->description(($change >= 0 ? 'Increased by ' : 'Decreased by ') . number_format($percentage, 2) . '% (last 30 days)')
            ->descriptionIcon($change >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
            ->chart($chart->map(fn (TrendValue $value) => $value->aggregate)->toArray())
            ->color($change >= 0 ? 'success' : 'danger');
    }

    public static function canView(): bool
    {
        return auth()->user()->hasPermission('admin.widgets.overview');
    }
}
