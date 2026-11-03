<?php

namespace App\Modules\HR\Talent\Http;

use App\Modules\HR\Talent\Application\RecruitmentService;
use Illuminate\Http\Request;

/** Public careers page API and offer replies. No login: the group slug and the unguessable offer token are the keys. */
class CareersController
{
    public function __construct(private RecruitmentService $rec) {}

    public function index(string $group)
    {
        return $this->rec->publicPosts($group);
    }

    public function apply(Request $r, string $group, int $post)
    {
        $d = $r->validate([
            'first_name' => ['required', 'string', 'max:80'], 'last_name' => ['required', 'string', 'max:80'], 'email' => ['required', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'], 'cover_note' => ['nullable', 'string', 'max:4000'], 'consent' => ['accepted'],
            'cv' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:5120'],
        ]);
        $a = $this->rec->apply($group, $post, $d, $r->file('cv'));

        return response()->json(['received' => true, 'reference' => 'APP-'.$a->id], 201);
    }

    public function offer(string $token)
    {
        return $this->rec->publicOffer($this->rec->offerByToken($token));
    }

    public function accept(string $token)
    {
        $o = $this->rec->accept($this->rec->offerByToken($token));

        return ['status' => $o->status];
    }

    public function decline(string $token)
    {
        return ['status' => $this->rec->decline($this->rec->offerByToken($token))->status];
    }
}
