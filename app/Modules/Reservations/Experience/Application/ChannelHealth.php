<?php

namespace App\Modules\Reservations\Experience\Application;

use App\Modules\Channels\Application\PushAri;
use App\Modules\Channels\Domain\Connection;
use App\Modules\Channels\Domain\SyncLog;
use Illuminate\Support\Carbon;

/**
 * Two-way sync health board: per channel connection the last push and pull, lag, failures since the last good
 * exchange, queued items and the last error. health: ok | warning | error | idle (never synced) | inactive.
 * Warning when a live connection has not pushed for PUSH_LAG_WARN minutes or pulled for PULL_LAG_WARN minutes.
 */
class ChannelHealth
{
    public const PUSH_LAG_WARN = 120;

    public const PULL_LAG_WARN = 240;

    public function board(?Carbon $now = null): array
    {
        $now ??= now();

        return Connection::orderBy('name')->get()->map(fn (Connection $c) => $this->row($c, $now))->all();
    }

    public function row(Connection $c, Carbon $now): array
    {
        $good = SyncLog::where('channel_connection_id', $c->id)->whereIn('status', ['sent', 'dry_run', 'processed'])->max('created_at');
        $failed = SyncLog::where('channel_connection_id', $c->id)->where('status', 'failed')->when($good, fn ($q) => $q->where('created_at', '>', $good));
        $failures = (clone $failed)->count();
        $last = (clone $failed)->orderByDesc('id')->first(['kind', 'direction', 'error', 'http_status', 'created_at']);
        $queued = SyncLog::where('channel_connection_id', $c->id)->where('status', 'queued')->count();
        $pushLag = $c->last_push_at ? (int) $c->last_push_at->diffInMinutes($now) : null;
        $pullLag = $c->last_pull_at ? (int) $c->last_pull_at->diffInMinutes($now) : null;

        $health = 'ok';
        if (! $c->isLive()) {
            $health = 'inactive';
        } elseif ($failures > 0) {
            $health = $failures >= 3 ? 'error' : 'warning';
        } elseif (! $c->last_push_at && ! $c->last_pull_at) {
            $health = 'idle';
        } elseif (($c->push_availability || $c->push_rates) && ($pushLag === null || $pushLag > self::PUSH_LAG_WARN)) {
            $health = 'warning';
        } elseif ($c->receive_reservations && $pullLag !== null && $pullLag > self::PULL_LAG_WARN) {
            $health = 'warning';
        }

        return [
            'id' => $c->id, 'name' => $c->name, 'provider' => $c->provider, 'status' => $c->status, 'environment' => $c->environment,
            'last_push_at' => $c->last_push_at, 'last_pull_at' => $c->last_pull_at, 'push_lag_minutes' => $pushLag, 'pull_lag_minutes' => $pullLag,
            'failures' => $failures, 'queued' => $queued, 'health' => $health,
            'last_error' => $last ? ['kind' => $last->kind, 'direction' => $last->direction, 'message' => $last->error, 'http_status' => $last->http_status, 'at' => $last->created_at] : null,
        ];
    }

    /** Push the full horizon again (de-duplicated while queued). */
    public function retry(Connection $c): void
    {
        PushAri::dispatch($c->id, now()->toDateString(), now()->addDays($c->sync_days)->toDateString());
    }
}
