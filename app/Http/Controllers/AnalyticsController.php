<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnalyticsRequest;
use App\Models\Farm;
use App\Services\AnalyticsService;
use App\Services\PurchaseAmounts;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Gate;

class AnalyticsController extends Controller
{
    public function index(AnalyticsRequest $request, AnalyticsService $service)
    {
        $filter = $request->validated();
        $farm = Farm::firstOrFail();
        $report = $service->build($request->user(), $farm, $filter);
        $format = fn ($row, $key) => $this->cell($row->$key ?? null, $key);
        if (($filter['format'] ?? null) === 'csv') {
            return response()->streamDownload(function () use ($report, $format) {
                $output = fopen('php://output', 'w');
                fwrite($output, "\xEF\xBB\xBF");
                fputcsv($output, array_values($report['columns']), ',', '"', '');
                foreach ($report['query']->cursor() as $row) {
                    $cells = [];
                    foreach (array_keys($report['columns']) as $key) {
                        $value = $format($row, $key);
                        $cells[] = ! is_numeric($value) && preg_match('/^[\s]*[=+\-@\t\r\n]/u', $value) ? "'".$value : $value;
                    }
                    fputcsv($output, $cells, ',', '"', '');
                }
                fclose($output);
            }, $filter['report'].'-'.$filter['from'].'-'.$filter['to'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        }
        $print = ($filter['format'] ?? null) === 'print';
        $chart = null;
        if (! $print && $report['chart']) {
            $points = (clone $report['query'])->limit(366)->get();
            $chart = ['labels' => $points->map(fn ($row) => $row->period.(isset($row->variety) ? ' · '.$row->variety : ''))->all(), 'datasets' => collect($report['chart'])->map(fn ($label, $key) => ['label' => $label, 'data' => $points->map(fn ($row) => (float) $row->$key)->all()])->values()->all()];
        }
        $rows = $print ? $report['query']->cursor() : $report['query']->paginate(30)->withQueryString();
        $reports = collect(AnalyticsService::REPORTS)->filter(fn ($label, $key) => Gate::allows('view-report', $key));

        return response()->view($print ? 'reports.print' : 'reports.index', compact('farm', 'report', 'filter', 'rows', 'chart', 'format', 'reports') + ['section' => 'reports'])->header('Cache-Control', 'private, no-store');
    }

    private function cell(mixed $value, string $key): string
    {
        if ($value === null) {
            return in_array($key, ['total_cost', 'cost_per_bag'], true) ? 'Unknown' : '';
        }
        if (in_array($key, ['revenue', 'cogs', 'gross_profit', 'net_profit', 'expenses', 'balance', 'recorded_cost', 'inventory_value', 'total_cost'], true)) {
            return (string) PurchaseAmounts::money($value);
        }
        if (in_array($key, ['quantity', 'minimum_stock', 'cost_per_bag', 'gross_kg', 'rejected_kg', 'saleable_kg'], true)) {
            return (string) BigDecimal::of(is_float($value) ? sprintf('%.6F', $value) : (string) $value)->toScale(6, RoundingMode::HalfUp);
        }

        return (string) $value;
    }
}
