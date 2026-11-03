<?php

namespace App\Modules\Accounting\Support;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/** Multi-sheet XLSX and delimited CSV output for the A-B exports (OpenSpout is already a dependency). */
final class Sheets
{
    /**
     * @param  array<string, array<int, array<int, scalar|null>>>  $sheets  sheet name => rows (first row is the header)
     */
    public static function xlsx(array $sheets): string
    {
        $file = tempnam(sys_get_temp_dir(), 'abx');
        $w = new Writer;
        $w->openToFile($file);
        $first = true;
        foreach ($sheets as $name => $rows) {
            if (! $first) {
                $w->addNewSheetAndMakeItCurrent();
            }
            $first = false;
            $w->getCurrentSheet()->setName(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', '-', (string) $name), 0, 31));
            foreach ($rows as $r) {
                $w->addRow(Row::fromValues(array_map(fn ($v) => is_bool($v) ? (int) $v : $v, $r)));
            }
        }
        $w->close();
        $out = (string) file_get_contents($file);
        @unlink($file);

        return $out;
    }

    public static function csv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        foreach ($rows as $r) {
            fputcsv($out, $r, ';', '"', '');
        }
        rewind($out);

        return "\xEF\xBB\xBF".stream_get_contents($out);
    }

    /** Streamed download response for a rendered file. */
    public static function download(string $content, string $filename, string $format)
    {
        $mime = $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8';

        return response($content, 200, ['Content-Type' => $mime, 'Content-Disposition' => "attachment; filename=\"$filename.$format\""]);
    }
}
