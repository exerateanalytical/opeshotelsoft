<?php

namespace App\Modules\Documents\Application;

use App\Modules\Documents\Domain\IssuedDocument;
use App\Modules\Documents\Domain\IssuedDocumentReprint;
use App\Modules\Tenancy\Domain\Hotel;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\DB;

/**
 * Register of issued documents. issue() is idempotent per (hotel, type, source): the first call
 * creates the record (the original); later calls return the same record and log a reprint.
 */
final class IssuedDocuments
{
    /**
     * @param  array{type: string, number: string, hotel: Hotel, source_type?: ?string, source_id?: ?int, data?: mixed, amount?: ?int, currency?: ?string, locale?: string, user_id?: ?int}  $spec
     * @return array{record: IssuedDocument, copy: int} copy 0 = original, n = "Duplicata n"
     */
    public function issue(array $spec): array
    {
        $hotel = $spec['hotel'];
        $sourceId = $spec['source_id'] ?? null;

        return DB::transaction(function () use ($spec, $hotel, $sourceId) {
            $q = IssuedDocument::withoutGlobalScopes()->where('hotel_id', $hotel->id)->where('type', $spec['type']);
            if ($sourceId !== null) {
                $q->where('source_type', $spec['source_type'] ?? $spec['type'])->where('source_id', $sourceId);
            } else {
                $q->where('number', $spec['number'])->whereNull('source_id');
            }
            $existing = $q->lockForUpdate()->first();
            if ($existing) {
                $existing->increment('copy_count');
                $existing->refresh();
                IssuedDocumentReprint::create([
                    'issued_document_id' => $existing->id, 'copy_number' => $existing->copy_count,
                    'printed_by' => $spec['user_id'] ?? null, 'printed_at' => now(), 'ip' => request()?->ip(),
                ]);

                return ['record' => $existing, 'copy' => $existing->copy_count];
            }
            $doc = new IssuedDocument([
                'type' => $spec['type'], 'number' => $spec['number'],
                'source_type' => $sourceId !== null ? ($spec['source_type'] ?? $spec['type']) : null, 'source_id' => $sourceId,
                'issued_at' => now(), 'issued_by' => $spec['user_id'] ?? null,
                'content_hash' => self::hash($spec['data'] ?? []), 'amount' => $spec['amount'] ?? null,
                'currency' => $spec['currency'] ?? null, 'locale' => $spec['locale'] ?? 'fr',
                'status' => 'valid', 'code' => 'pending',
            ]);
            $doc->group_id = $hotel->group_id;
            $doc->hotel_id = $hotel->id;
            $doc->save();
            $doc->update(['code' => self::codeFor($doc)]);
            $doc->refresh();

            return ['record' => $doc, 'copy' => 0];
        });
    }

    /** Short id (base36) + HMAC: a code cannot be guessed from a neighbouring one. */
    public static function codeFor(IssuedDocument $d): string
    {
        return base_convert((string) $d->id, 10, 36).'-'.self::mac($d->id, $d->type, $d->number);
    }

    private static function mac(int $id, string $type, string $number): string
    {
        return substr(hash_hmac('sha256', "$id|$type|$number", (string) config('app.key')), 0, 10);
    }

    /** Resolve a verify code to its record (any tenant: the page is public) or null. */
    public function findByCode(string $code): ?IssuedDocument
    {
        if (! preg_match('/^([0-9a-z]{1,12})-([0-9a-f]{10})$/', $code, $m)) {
            return null;
        }
        $doc = IssuedDocument::withoutGlobalScopes()->find((int) base_convert($m[1], 36, 10));

        return $doc && hash_equals(self::mac($doc->id, $doc->type, $doc->number), $m[2]) ? $doc : null;
    }

    /** Mark the document(s) issued for a source as voided. Returns how many were flipped. */
    public function voidSource(string $type, ?string $sourceType, int $sourceId, string $reason = ''): int
    {
        return $this->flip($type, $sourceType, $sourceId, 'voided', $reason, null);
    }

    /** Mark the documents of a source as replaced by a later one. */
    public function replaceSource(string $type, ?string $sourceType, int $sourceId, ?IssuedDocument $by = null, string $reason = ''): int
    {
        return $this->flip($type, $sourceType, $sourceId, 'replaced', $reason, $by?->id);
    }

    private function flip(string $type, ?string $sourceType, int $sourceId, string $status, string $reason, ?int $by): int
    {
        return IssuedDocument::withoutGlobalScopes()
            ->where('type', $type)->where('source_type', $sourceType ?? $type)->where('source_id', $sourceId)
            ->where('status', 'valid')
            ->update(['status' => $status, 'status_reason' => $reason ?: null, 'status_at' => now(), 'replaced_by_id' => $by]);
    }

    /** SHA-256 over a canonical JSON form of the data the document was rendered from. */
    public static function hash(mixed $data): string
    {
        return hash('sha256', (string) json_encode(self::canon($data), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    private static function canon(mixed $v, int $depth = 0): mixed
    {
        if ($depth > 8 || $v instanceof \Closure) {
            return null;
        }
        if ($v instanceof Hotel) {
            return $v->id;
        }
        if ($v instanceof Arrayable) {
            $v = $v->toArray();
        } elseif ($v instanceof \JsonSerializable) {
            $v = $v->jsonSerialize();
        } elseif (is_object($v)) {
            $v = method_exists($v, 'toArray') ? $v->toArray() : (array) $v;
        }
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $x) {
                if (is_string($x) && str_starts_with($x, 'data:image')) {
                    $x = md5($x); // logos and signatures: stable but compact
                }
                $out[$k] = self::canon($x, $depth + 1);
            }
            if (! array_is_list($out)) {
                ksort($out);
            }

            return $out;
        }

        return is_scalar($v) || $v === null ? $v : null;
    }
}
