<?php

namespace App\Modules\Accounting\Payables;

use App\Platform\Domain\DomainException;
use Illuminate\Support\Carbon;

/** MT940 and CAMT.053 bank statement parsers. Output rows: [date Y-m-d, amount signed integer, reference, description]. XAF has no minor unit: decimals are rounded. */
final class StatementParser
{
    public const MAX_BYTES = 5_242_880;

    /** @return array{rows: array<int, array{date:string, amount:int, reference:string, description:string}>, account: ?string, opening: ?int, closing: ?int} */
    public static function parse(string $format, string $content): array
    {
        if (strlen($content) > self::MAX_BYTES) {
            throw new DomainException('The statement file is too large (5 MB maximum).', 'file-too-large');
        }

        return match ($format) {
            'mt940' => self::mt940($content),
            'camt053' => self::camt053($content),
            default => throw new DomainException("Unsupported statement format $format.", 'unsupported-format'),
        };
    }

    /** Canonical CSV consumed by Reconciliation::import (columns date,amount,reference,description). */
    public static function toCsv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['date', 'amount', 'reference', 'description'], ';', '"', '');
        foreach ($rows as $r) {
            fputcsv($out, [$r['date'], $r['amount'], $r['reference'], $r['description']], ';', '"', '');
        }
        rewind($out);

        return stream_get_contents($out);
    }

    private static function mt940(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim(preg_replace('/^\xEF\xBB\xBF/', '', $text)));
        $account = $opening = $closing = null;
        $rows = [];
        $cur = null;
        $flush = function () use (&$cur, &$rows) {
            if ($cur) {
                $cur['description'] = trim(preg_replace('/\s+/', ' ', preg_replace('/\?\d{2}/', ' ', $cur['description']))) ?: $cur['reference'];
                $rows[] = $cur;
                $cur = null;
            }
        };
        $tag = null;
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^:(\d{2}[A-Z]?):(.*)$/', $line, $m)) {
                $tag = $m[1];
                $val = $m[2];
                if ($tag === '61') {
                    $flush();
                    if (! preg_match('/^(\d{2})(\d{2})(\d{2})(?:\d{4})?(RC|RD|C|D)[A-Z]?([\d,\.]+)N([A-Z0-9]{3})(.*)$/', $val, $f)) {
                        throw new DomainException("Unreadable MT940 line :61:$val", 'bad-statement');
                    }
                    $amount = self::amount($f[5]);
                    $sign = in_array($f[4], ['C', 'RD'], true) ? 1 : -1;
                    $ref = trim(explode('//', $f[7])[0]);
                    $cur = ['date' => Carbon::create(2000 + (int) $f[1], (int) $f[2], (int) $f[3])->toDateString(), 'amount' => $sign * $amount,
                        'reference' => $ref !== '' && strtoupper($ref) !== 'NONREF' ? $ref : '', 'description' => ''];
                } elseif ($tag === '86') {
                    if ($cur) {
                        $cur['description'] .= ' '.$val;
                    }
                } elseif ($tag === '25') {
                    $account = trim($val);
                } elseif ($tag === '60F' || $tag === '60M') {
                    $opening = self::balance($val);
                } elseif ($tag === '62F' || $tag === '62M') {
                    $closing = self::balance($val);
                }
            } elseif ($tag === '86' && $cur && ! str_starts_with($line, '-')) {
                $cur['description'] .= ' '.$line;
            }
        }
        $flush();
        if (! $rows) {
            throw new DomainException('No transaction found in the MT940 file.', 'empty-statement');
        }
        foreach ($rows as &$r) {
            $r['reference'] = $r['reference'] !== '' ? $r['reference'] : substr(sha1($r['date'].$r['description'].$r['amount']), 0, 12);
        }

        return ['rows' => $rows, 'account' => $account, 'opening' => $opening, 'closing' => $closing];
    }

    private static function camt053(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        $doc = new \SimpleXMLElement($xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($prev);
        $x = fn (\SimpleXMLElement $n, string $path) => $n->xpath($path) ?: [];
        $first = fn (\SimpleXMLElement $n, string $path) => ($r = $x($n, $path)) ? trim((string) $r[0]) : '';
        $rows = [];
        $account = $opening = $closing = null;
        foreach ($x($doc, '//*[local-name()="Stmt"]') as $stmt) {
            $account ??= $first($stmt, './/*[local-name()="Acct"]/*[local-name()="Id"]/*[local-name()="IBAN" or local-name()="Id"]') ?: null;
            foreach ($x($stmt, './*[local-name()="Bal"]') as $bal) {
                $code = $first($bal, './/*[local-name()="Cd"]');
                $v = (int) round((float) $first($bal, './*[local-name()="Amt"]')) * ($first($bal, './*[local-name()="CdtDbtInd"]') === 'DBIT' ? -1 : 1);
                if (in_array($code, ['OPBD', 'PRCD'], true)) {
                    $opening = $v;
                } elseif (in_array($code, ['CLBD', 'CLAV'], true)) {
                    $closing = $v;
                }
            }
            foreach ($x($stmt, './*[local-name()="Ntry"]') as $e) {
                $amount = (int) round((float) $first($e, './*[local-name()="Amt"]'));
                $debit = $first($e, './*[local-name()="CdtDbtInd"]') === 'DBIT';
                if ($first($e, './*[local-name()="RvslInd"]') === 'true') {
                    $debit = ! $debit;
                }
                $date = $first($e, './*[local-name()="BookgDt"]/*[local-name()="Dt"]') ?: $first($e, './*[local-name()="BookgDt"]/*[local-name()="DtTm"]') ?: $first($e, './*[local-name()="ValDt"]/*[local-name()="Dt"]');
                $parts = array_filter([
                    $first($e, './/*[local-name()="RmtInf"]/*[local-name()="Ustrd"]'), $first($e, './*[local-name()="AddtlNtryInf"]'),
                    $first($e, './/*[local-name()="RltdPties"]//*[local-name()="Nm"]'),
                ]);
                $ref = $first($e, './/*[local-name()="EndToEndId"]');
                $ref = ($ref === '' || $ref === 'NOTPROVIDED') ? ($first($e, './*[local-name()="AcctSvcrRef"]') ?: $first($e, './*[local-name()="NtryRef"]')) : $ref;
                $desc = trim(implode(' ', $parts));
                $rows[] = ['date' => Carbon::parse(substr($date, 0, 10))->toDateString(), 'amount' => $debit ? -$amount : $amount,
                    'reference' => $ref !== '' ? $ref : substr(sha1($date.$desc.$amount), 0, 12), 'description' => $desc !== '' ? $desc : 'CAMT entry'];
            }
        }
        if (! $rows) {
            throw new DomainException('No entry found in the CAMT.053 file.', 'empty-statement');
        }

        return ['rows' => $rows, 'account' => $account, 'opening' => $opening, 'closing' => $closing];
    }

    private static function amount(string $v): int
    {
        return (int) round((float) str_replace(',', '.', $v));
    }

    private static function balance(string $v): ?int
    {
        return preg_match('/^([CD])\d{6}[A-Z]{3}([\d,\.]+)/', $v, $m) ? ($m[1] === 'D' ? -1 : 1) * self::amount($m[2]) : null;
    }
}
