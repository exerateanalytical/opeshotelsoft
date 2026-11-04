<?php

namespace App\Modules\Documents\Application;

use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/** Renders any registered report as xlsx, csv or pdf. */
final class Exporter
{
    public const MIME = [
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv' => 'text/csv; charset=UTF-8',
        'pdf' => 'application/pdf',
    ];

    /** Hard cap on exported rows from a paginated source. */
    public const MAX_ROWS = 5000;

    public function __construct(private InternalApi $api, private TenantContext $ctx) {}

    /** @return array{content: string, mime: string, filename: string} */
    public function render(string $report, array $params, string $format): array
    {
        if (! isset(self::MIME[$format])) {
            throw new DomainException("Unknown format '$format'.", 'unknown_format');
        }
        [$def, $path, $query] = ReportRegistry::resolve($report, $params);
        unset($query['format'], $query['lang']);
        unset($query['page']);
        [$data, $truncated] = $this->fetchAll($path, $query);
        $rows = array_map(fn ($r) => (array) $r, array_values(($def['rows'])($data, $query)));
        $columns = $def['columns'] ?? array_keys($rows[0] ?? []);
        $matrix = array_map(fn ($r) => array_map(fn ($c) => self::cell($r[$c] ?? null), $columns), $rows);
        if ($truncated && $columns) {
            $note = array_fill(0, count($columns), null);
            $note[0] = sprintf('TRUNCATED: export limited to the first %d rows; narrow the filters to export the rest.', self::MAX_ROWS);
            $matrix[] = $note;
        }
        // Human-facing formats get translated headers; CSV keeps the column codes for re-import.
        $headers = array_map(fn ($c) => self::header((string) $c), $columns);

        $content = match ($format) {
            'csv' => $this->csv($columns, $matrix),
            'xlsx' => $this->xlsx($headers, $matrix),
            'pdf' => $this->reportPdf($report, $def, $headers, $matrix, $query, count($columns) > 7),
        };

        return ['content' => $content, 'mime' => self::MIME[$format], 'filename' => $report.'-'.now()->format('Ymd-His').'.'.$format];
    }

    /**
     * Fetches the whole filtered set: paginated list answers ({data, last_page}) are followed page by page
     * up to MAX_ROWS. The list endpoints' own page size is untouched.
     *
     * @return array{0: mixed, 1: bool} [data, truncated]
     */
    private function fetchAll(string $path, array $query): array
    {
        $data = $this->api->get($path, $query) ?? [];
        if (! is_array($data) || ! isset($data['data'], $data['last_page']) || ! is_array($data['data'])) {
            return [$data, false];
        }
        $last = (int) $data['last_page'];
        for ($page = 2; $page <= $last && count($data['data']) < self::MAX_ROWS; $page++) {
            $next = $this->api->get($path, $query + ['page' => $page]);
            $data['data'] = array_merge($data['data'], $next['data'] ?? []);
        }
        $truncated = count($data['data']) > self::MAX_ROWS || ($page <= $last);
        if (count($data['data']) > self::MAX_ROWS) {
            $data['data'] = array_slice($data['data'], 0, self::MAX_ROWS);
        }

        return [$data, $truncated];
    }

    /** Reports go through the engine: every export is a new issued document (number = report + timestamp). */
    private function reportPdf(string $report, array $def, array $headers, array $matrix, array $query, bool $wide): string
    {
        $lang = app()->getLocale() === 'en' ? 'en' : 'fr';

        return app(DocumentRenderer::class)->render('report', ['title' => $def['title'], 'columns' => $headers, 'rows' => $matrix, 'params' => $query], [
            'view' => 'pdf.report', 'size' => $wide ? 'a4-landscape' : 'a4', 'lang' => $lang, 'title' => (string) $def['title'],
            'number' => strtoupper($report).'-'.now()->format('Ymd-His'), 'filename' => $report.'-'.now()->format('Ymd-His'),
        ])->pdf;
    }

    private static function header(string $column): string
    {
        $t = __("srv.col.$column");

        return $t === "srv.col.$column" ? $column : $t;
    }

    private static function cell(mixed $v): string|int|float|null
    {
        return match (true) {
            is_bool($v) => $v ? 1 : 0,
            is_array($v) => json_encode($v, JSON_UNESCAPED_UNICODE),
            // Spreadsheet formula injection: a cell that starts with = + - @ or a tab/CR is neutralised (SEC1 audit).
            is_string($v) && $v !== '' && ! is_numeric($v) && strpbrk($v[0], "=+-@\t\r") !== false => "'".$v,
            default => $v,
        };
    }

    private function csv(array $columns, array $rows): string
    {
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\u{FEFF}"); // UTF-8 BOM so Excel keeps the accents
        fputcsv($out, $columns, ';', '"', '');
        foreach ($rows as $r) {
            fputcsv($out, $r, ';', '"', '');
        }
        rewind($out);
        $s = stream_get_contents($out);
        fclose($out);

        return $s;
    }

    private function xlsx(array $columns, array $rows): string
    {
        $file = tempnam(sys_get_temp_dir(), 'xlsx');
        $w = new Writer;
        $w->openToFile($file);
        $w->addRow(Row::fromValues($columns));
        foreach ($rows as $r) {
            $w->addRow(Row::fromValues($r));
        }
        $w->close();
        $s = file_get_contents($file);
        @unlink($file);

        return $s;
    }
}
