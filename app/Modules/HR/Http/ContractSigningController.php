<?php

namespace App\Modules\HR\Http;

use App\Modules\HR\Application\Lifecycle\EContractService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Public signing endpoints reached from the link sent to the employee's phone (no login; the token is the credential). */
class ContractSigningController
{
    public function __construct(private EContractService $contracts) {}

    public function show(string $token)
    {
        return $this->contracts->publicView($this->contracts->byToken($token));
    }

    public function otp(Request $request, string $token)
    {
        $doc = $this->contracts->byToken($token);
        $d = $request->validate(['channel' => ['required', Rule::in(['sms', 'whatsapp', 'email'])]]);
        $this->contracts->requestOtp($doc, $d['channel'], $request->ip(), $request->userAgent());

        return ['sent' => true, 'minutes' => EContractService::OTP_MINUTES];
    }

    public function sign(Request $request, string $token)
    {
        $doc = $this->contracts->byToken($token);
        $d = $request->validate(['otp' => ['required', 'string', 'max:10'], 'signature' => ['required', 'string'], 'agree' => ['accepted']]);
        $doc = $this->contracts->employeeSign($doc, $d['otp'], $d['signature'], $request->ip(), $request->userAgent());

        return ['status' => $doc->status, 'signed_at' => $doc->employee_signed_at];
    }
}
