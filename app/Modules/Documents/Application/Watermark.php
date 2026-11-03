<?php

namespace App\Modules\Documents\Application;

use App\Modules\Tenancy\Domain\Group;
use App\Modules\Tenancy\Domain\Hotel;
use Illuminate\Support\Facades\Storage;

/**
 * Platform watermark, stored per group in groups.settings['watermark'] and drawn on every PDF.
 * mode: none | text | image. position: center | diagonal | tile. apply: all | drafts (drafts and copies only).
 */
final class Watermark
{
    public const POSITIONS = ['center', 'diagonal', 'tile'];

    public const DISK = 'local';

    public static function settings(?Group $group): array
    {
        $w = (array) ($group?->settings['watermark'] ?? []);

        return [
            'mode' => in_array($w['mode'] ?? null, ['text', 'image'], true) ? $w['mode'] : 'none',
            'text' => (string) ($w['text'] ?? ''),
            'image_path' => $w['image_path'] ?? null,
            'opacity' => max(2, min(60, (int) ($w['opacity'] ?? 12))),
            'position' => in_array($w['position'] ?? null, self::POSITIONS, true) ? $w['position'] : 'diagonal',
            'apply' => ($w['apply'] ?? 'all') === 'drafts' ? 'drafts' : 'all',
        ];
    }

    public static function save(Group $group, array $data): array
    {
        $cur = self::settings($group);
        $new = array_merge($cur, array_intersect_key($data, $cur));
        $s = $group->settings ?? [];
        $s['watermark'] = $new;
        $group->update(['settings' => $s]);

        return self::settings($group->fresh());
    }

    public static function storeImage(Group $group, string $contents, string $ext): array
    {
        $path = "branding/group-{$group->id}/watermark-".now()->format('YmdHis').".$ext";
        $old = self::settings($group)['image_path'];
        Storage::disk(self::DISK)->put($path, $contents);
        if ($old && $old !== $path) {
            Storage::disk(self::DISK)->delete($old);
        }

        return self::save($group, ['image_path' => $path, 'mode' => 'image']);
    }

    public static function removeImage(Group $group): array
    {
        $old = self::settings($group)['image_path'];
        if ($old) {
            Storage::disk(self::DISK)->delete($old);
        }

        return self::save($group, ['image_path' => null, 'mode' => self::settings($group)['mode'] === 'image' ? 'none' : self::settings($group)['mode']]);
    }

    public static function imageUri(array $s): ?string
    {
        $p = $s['image_path'] ?? null;
        if (! $p || ! Storage::disk(self::DISK)->exists($p)) {
            return null;
        }

        return 'data:'.(str_ends_with($p, '.png') ? 'image/png' : 'image/jpeg').';base64,'.base64_encode(Storage::disk(self::DISK)->get($p));
    }

    /** View model for the layout, or null when no watermark applies to this render. */
    public static function forRender(?Hotel $hotel, bool $draft, int $copy): ?array
    {
        $group = $hotel ? Group::find($hotel->group_id) : null;
        $s = self::settings($group);
        if ($s['mode'] === 'none' || ($s['apply'] === 'drafts' && ! $draft && $copy < 1)) {
            return null;
        }
        $img = $s['mode'] === 'image' ? self::imageUri($s) : null;
        if ($s['mode'] === 'image' && ! $img) {
            return null;
        }
        if ($s['mode'] === 'text' && trim($s['text']) === '') {
            return null;
        }

        return ['mode' => $s['mode'], 'text' => $s['text'], 'image' => $img, 'opacity' => $s['opacity'], 'position' => $s['position']];
    }
}
