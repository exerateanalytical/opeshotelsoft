<?php

namespace App\Modules\Accounting\Application\Core;

use App\Modules\Accounting\Application\Ledger;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Platform\Domain\DomainException;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Manual journal entries that must carry at least one supporting document; attachments are immutable (never deleted). */
class ManualEntries
{
    public const MAX_KB = 10240;

    public function __construct(private Ledger $ledger, private TenantContext $ctx, private AuditTrail $audit) {}

    /** @param UploadedFile[] $files */
    public function post(string $journal, string $date, string $description, array $lines, array $files): JournalEntry
    {
        if (! $files) {
            throw new DomainException('A manual entry needs at least one supporting document.', 'attachment-required');
        }

        return DB::transaction(function () use ($journal, $date, $description, $lines, $files) {
            $entry = $this->ledger->post($journal, $date, $description, $lines, ['source_type' => 'manual', 'approved_by' => $this->ctx->actor()?->id])
                ?? throw new DomainException('Nothing to post.', 'empty-entry');
            foreach ($files as $f) {
                $this->attach($entry, $f);
            }

            return $entry->load('lines');
        });
    }

    public function attach(JournalEntry $entry, UploadedFile $file): array
    {
        $h = $this->ctx->requireHotelId();
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $file->getClientOriginalName()) ?: 'document';
        $path = $file->storeAs("accounting/attachments/$h/{$entry->id}", bin2hex(random_bytes(6)).'-'.$name, 'local');
        $id = DB::table('journal_attachments')->insertGetId([
            'group_id' => $this->ctx->groupId(), 'hotel_id' => $h, 'journal_entry_id' => $entry->id, 'filename' => $name,
            'mime' => $file->getMimeType() ?: 'application/octet-stream', 'size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()),
            'path' => $path, 'uploaded_by' => $this->ctx->actor()?->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('attachment_added', 'journal_entry', $entry->id, ['attachment' => $id, 'filename' => $name, 'size' => $file->getSize()]);

        return (array) DB::table('journal_attachments')->find($id);
    }

    public function attachments(int $entryId): array
    {
        return DB::table('journal_attachments')->where(['hotel_id' => $this->ctx->requireHotelId(), 'journal_entry_id' => $entryId])->orderBy('id')
            ->get(['id', 'filename', 'mime', 'size', 'sha256', 'uploaded_by', 'created_at'])->all();
    }

    public function download(int $attachmentId): array
    {
        $a = DB::table('journal_attachments')->where(['id' => $attachmentId, 'hotel_id' => $this->ctx->requireHotelId()])->first() ?? throw new DomainException('Attachment not found.', 'not-found');
        $this->audit->record('attachment_downloaded', 'journal_entry', $a->journal_entry_id, ['attachment' => $a->id]);

        return [Storage::disk('local')->path($a->path), $a->filename, $a->mime];
    }
}
