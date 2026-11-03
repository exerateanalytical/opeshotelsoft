<?php

namespace App\Modules\Documents\Application;

use App\Models\User;
use App\Modules\Documents\Domain\ScheduledReport;
use App\Platform\Tenancy\TenantContext;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Renders a scheduled report as its creator (so it only ever contains what that
 * user may see) and emails it as an attachment through Laravel Mail. With no
 * mail credentials, MAIL_MAILER=log writes the message to the log instead.
 * (W04 Notifications has no attachment support yet, so Mail is used directly.)
 */
final class ScheduledReportRunner
{
    public function __construct(private Exporter $exporter, private TenantContext $ctx) {}

    public function run(ScheduledReport $s): bool
    {
        $saved = [$this->ctx->groupId(), $this->ctx->hotelId(), $this->ctx->allowedHotelIds(), $this->ctx->actor()];
        $user = User::find($s->created_by);
        try {
            if (! $user) {
                throw new \RuntimeException('Creator no longer exists.');
            }
            Auth::guard('api')->setUser($user);
            $this->ctx->set($s->group_id, $s->hotel_id, $user->allowedHotelIds(), $user);
            $file = $this->exporter->render($s->report, $s->params ?? [], $s->format);
            $body = "Rapport planifié / Scheduled report: {$s->name}\nGénéré le / Generated: ".now()->toDateTimeString();
            Mail::raw($body, function (Message $m) use ($s, $file) {
                $m->to($s->recipients)->subject('[OpesHotelSoft] '.$s->name)
                    ->attachData($file['content'], $file['filename'], ['mime' => $file['mime']]);
            });
            $s->last_status = 'sent';
            $s->last_error = null;
            $ok = true;
        } catch (Throwable $e) {
            $s->last_status = 'failed';
            $s->last_error = $e instanceof HttpResponseException
                ? 'HTTP '.$e->getResponse()->getStatusCode().': '.substr((string) $e->getResponse()->getContent(), 0, 500)
                : substr($e->getMessage(), 0, 500);
            $ok = false;
        } finally {
            $this->ctx->set(...$saved);
        }
        $s->last_run_at = now();
        $s->computeNextRun();
        $s->save();

        return $ok;
    }
}
