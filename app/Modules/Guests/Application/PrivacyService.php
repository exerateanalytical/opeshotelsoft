<?php

namespace App\Modules\Guests\Application;

use App\Modules\Guests\Domain\Guest;
use App\Modules\Guests\Domain\PrivacyRequest;
use App\Platform\Domain\DomainException;
use App\Platform\Events\EventBus;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Data-protection requests (SPEC §4.5, §5). A request is raised by staff with
 * guests.manage and executed only after a second person approves it.
 * Financial records are never deleted: folios, invoices, reservations and POS
 * checks keep their rows and amounts; only personal fields are blanked.
 */
class PrivacyService
{
    public function __construct(private EventBus $bus, private TenantContext $ctx) {}

    public function request(Guest $guest, string $kind, string $reason): PrivacyRequest
    {
        if (PrivacyRequest::where('guest_id', $guest->id)->where('status', 'approved')->exists()) {
            throw new DomainException('This guest has already been anonymised.', 'guest-anonymised');
        }
        if (PrivacyRequest::where('guest_id', $guest->id)->where('status', 'pending')->exists()) {
            throw new DomainException('A data-protection request is already pending for this guest.', 'privacy-request-pending');
        }
        $req = PrivacyRequest::create([
            'guest_id' => $guest->id, 'kind' => $kind, 'reason' => $reason, 'requested_by' => $this->ctx->actor()?->id,
        ]);
        $this->bus->record('crm.privacy_requested', ['id' => $req->id, 'guest_id' => $guest->id, 'kind' => $kind], 'guest', $guest->id);
        $this->audit($guest->id, 'privacy request raised', ['request_id' => $req->id, 'kind' => $kind]);

        return $req->fresh();
    }

    public function approve(PrivacyRequest $req, ?string $note = null): PrivacyRequest
    {
        $this->assertDecidable($req);

        return DB::transaction(function () use ($req, $note) {
            $guest = Guest::withTrashed()->findOrFail($req->guest_id);
            $this->anonymise($guest);
            if ($req->kind === 'delete') {
                $guest->fresh()->delete(); // soft delete: the row stays so financial history keeps its foreign key
            }
            $req->update(['status' => 'approved', 'decided_by' => $this->ctx->actor()?->id, 'decided_at' => now(), 'decision_note' => $note]);
            $this->bus->record('crm.guest_anonymised', ['request_id' => $req->id, 'guest_id' => $guest->id, 'kind' => $req->kind], 'guest', $guest->id);
            $this->audit($guest->id, 'guest personal data anonymised', ['request_id' => $req->id, 'kind' => $req->kind]);

            return $req->fresh();
        });
    }

    public function reject(PrivacyRequest $req, string $note): PrivacyRequest
    {
        $this->assertDecidable($req);
        $req->update(['status' => 'rejected', 'decided_by' => $this->ctx->actor()?->id, 'decided_at' => now(), 'decision_note' => $note]);
        $this->audit($req->guest_id, 'privacy request rejected', ['request_id' => $req->id]);

        return $req;
    }

    private function assertDecidable(PrivacyRequest $req): void
    {
        if ($req->status !== 'pending') {
            throw new DomainException("This request is already {$req->status}.", 'privacy-request-closed');
        }
        if ($req->requested_by && $req->requested_by === $this->ctx->actor()?->id) {
            throw new DomainException('A second person must approve a data-protection request.', 'four-eyes');
        }
    }

    /** Blank personal fields on the profile, police register entries and company contacts. */
    private function anonymise(Guest $guest): void
    {
        $label = 'Anonymised';
        DB::table('guests')->where('id', $guest->id)->update([
            'title' => null, 'first_name' => $label, 'last_name' => 'Guest #'.$guest->id, 'email' => null, 'phone' => null,
            'gender' => null, 'date_of_birth' => null, 'nationality' => null, 'country' => null, 'city' => null, 'address' => null,
            'id_type' => null, 'id_number' => null, 'id_expiry' => null, 'id_issued_in' => null, 'profession' => null,
            'blacklist_reason' => null, 'preferences' => '{}', 'notes' => null, 'marketing_consent' => false, 'consent_at' => null,
            'updated_at' => now(),
        ]);

        $register = DB::table('guest_register_entries')->where('guest_id', $guest->id);
        $files = [];
        foreach (['id_photo_path', 'signature_path'] as $col) {
            if (Schema::hasColumn('guest_register_entries', $col)) {
                $files = array_merge($files, (clone $register)->whereNotNull($col)->pluck($col)->all());
            }
        }
        $blank = [
            'full_name' => $label, 'nationality' => null, 'date_of_birth' => null, 'place_of_birth' => null, 'profession' => null,
            'id_type' => null, 'id_number' => null, 'id_expiry' => null, 'coming_from' => null, 'going_to' => null, 'purpose' => null,
            'updated_at' => now(),
        ];
        foreach (['id_photo_path', 'signature_path'] as $col) {
            if (Schema::hasColumn('guest_register_entries', $col)) {
                $blank[$col] = null;
            }
        }
        (clone $register)->update($blank);
        foreach ($files as $path) {
            Storage::delete($path);
        }

        // SEC2: financial and payment copies of the person's data. Amounts, accounts, numbers and statuses stay as they
        // are (the books still balance and every document keeps its number and verify code); only the name and contact
        // details of the individual are replaced.
        $anon = $label.' Guest #'.$guest->id;
        $reservationIds = DB::table('reservations')->where('guest_id', $guest->id)->pluck('id')->all();
        $folioIds = DB::table('folios')->where('guest_id', $guest->id)->whereNull('company_id')->pluck('id')->all();
        DB::table('folios')->whereIn('id', $folioIds)->update(['name' => $anon, 'updated_at' => now()]);
        // Invoices to the individual only: a company invoice keeps its bill-to (the company is the customer).
        $invoiceIds = DB::table('invoices')->where('guest_id', $guest->id)->whereNull('company_id')->pluck('id')->all();
        DB::table('invoices')->whereIn('id', $invoiceIds)
            ->update(['bill_to_name' => $anon, 'bill_to_tax_id' => null, 'bill_to_address' => null, 'updated_at' => now()]);
        if (Schema::hasTable('payment_intents')) {
            DB::table('payment_intents')->where(fn ($q) => $q->whereIn('folio_id', $folioIds)->orWhereIn('reservation_id', $reservationIds))
                ->update(['payer_phone' => null, 'payer_email' => null, 'updated_at' => now()]);
        }
        if (Schema::hasTable('payment_links')) {
            DB::table('payment_links')->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('target_type', 'folio')->whereIn('target_id', $folioIds))
                ->orWhere(fn ($w) => $w->where('target_type', 'reservation')->whereIn('target_id', $reservationIds))
                ->orWhere(fn ($w) => $w->where('target_type', 'invoice')->whereIn('target_id', $invoiceIds)))
                ->update(['payer_phone' => null, 'updated_at' => now()]);
        }
        if (Schema::hasTable('guest_portal_checkins')) {
            $checkins = DB::table('guest_portal_checkins')->whereIn('reservation_id', $reservationIds);
            $photos = (clone $checkins)->whereNotNull('id_photo_path')->pluck('id_photo_path')->all();
            (clone $checkins)->update(['id_number' => '', 'nationality' => null, 'id_expiry' => null, 'id_country' => null,
                'birth_date' => null, 'address' => null, 'id_photo_path' => null, 'signature' => null, 'notes' => null, 'updated_at' => now()]);
            foreach ($photos as $path) {
                Storage::delete($path);
            }
        }

        DB::table('company_contacts')->where('guest_id', $guest->id)
            ->update(['name' => $label, 'email' => null, 'phone' => null, 'guest_id' => null, 'updated_at' => now()]);

        // Earlier audit entries for this profile hold the old values; keep who/when but drop the personal payload.
        DB::table('activity_log')->where('subject_type', $guest->getMorphClass())->where('subject_id', $guest->id)
            ->where(fn ($q) => $q->whereNull('log_name')->orWhere('log_name', '!=', 'data-protection'))
            ->update(['properties' => json_encode(['anonymised' => true])]);
    }

    private function audit(int $guestId, string $message, array $props): void
    {
        activity('data-protection')
            ->performedOn((new Guest)->forceFill(['id' => $guestId]))
            ->causedBy($this->ctx->actor())
            ->withProperties($props)
            ->log($message);
    }
}
