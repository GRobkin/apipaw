<?php
declare(strict_types=1);

namespace PawLife\Resources;

use PawLife\Http\HttpException;

/** Bounded JPEG input, always decoded and re-encoded before accepting new data. */
final class PhotoInput
{
    public const MAX_URI_BYTES = 409600;
    public const MAX_SIDE = 2048;
    public const MAX_PIXELS = 4194304;

    public static function prepare(array $data, array $existing = [], ?callable $beforeDecode = null): array
    {
        if (!array_key_exists('fotoUrl', $data) || $data['fotoUrl'] === null) return $data;
        // Preserve an unchanged historical value; never fetch its URL server-side.
        if (array_key_exists('fotoUrl', $existing) && $data['fotoUrl'] === $existing['fotoUrl']) return $data;
        if ($beforeDecode !== null) $beforeDecode();
        $data['fotoUrl'] = self::normalize($data['fotoUrl']);
        return $data;
    }

    public static function normalize(mixed $uri): string
    {
        $prefix = 'data:image/jpeg;base64,';
        if (!is_string($uri) || strlen($uri) > self::MAX_URI_BYTES || !str_starts_with($uri, $prefix)) self::invalid();
        $encoded = substr($uri, strlen($prefix));
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || $bytes === '' || base64_encode($bytes) !== $encoded) self::invalid();
        $size = @getimagesizefromstring($bytes);
        if ($size === false || ($size[2] ?? null) !== IMAGETYPE_JPEG || $size[0] < 1 || $size[1] < 1
            || $size[0] > self::MAX_SIDE || $size[1] > self::MAX_SIDE
            || $size[0] * $size[1] > self::MAX_PIXELS) self::invalid();
        $bytes = self::stripMetadata($bytes);
        if (self::hasDecoder()) {
            // GD tolerates some truncated files with warnings: reject those too.
            $warning = false;
            set_error_handler(static function () use (&$warning): bool { $warning = true; return true; });
            try { $image = imagecreatefromstring($bytes); }
            finally { restore_error_handler(); }
            if ($image === false || $warning) {
                unset($image);
                self::invalid();
            }
            ob_start();
            try {
                $ok = imagejpeg($image, null, 82);
                $output = ob_get_contents();
            } finally { ob_end_clean(); unset($image); }
            if (!$ok || !is_string($output) || $output === '') self::invalid();
            $bytes = $output;
        } else {
            $bytes = NodePhotoDecoder::decode($bytes);
        }
        $result = $prefix . base64_encode($bytes);
        if (strlen($result) > self::MAX_URI_BYTES) self::invalid();
        return $result;
    }

    public static function hasDecoder(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagejpeg');
    }

    /** Walk markers, including progressive scan boundaries; discard APP/COM. */
    private static function stripMetadata(string $bytes): string
    {
        if (!str_starts_with($bytes, "\xFF\xD8")) self::invalid();
        $out = "\xFF\xD8"; $offset = 2; $length = strlen($bytes); $scan = false; $sawScan = false;
        while ($offset < $length) {
            if ($scan) {
                $next = strpos($bytes, "\xFF", $offset);
                if ($next === false) self::invalid();
                $out .= substr($bytes, $offset, $next - $offset); $offset = $next;
            }
            if ($bytes[$offset] !== "\xFF") self::invalid();
            $start = $offset;
            while ($offset < $length && $bytes[$offset] === "\xFF") $offset++;
            if ($offset >= $length) self::invalid();
            $marker = ord($bytes[$offset++]);
            if ($scan && ($marker === 0 || ($marker >= 0xD0 && $marker <= 0xD7))) {
                $out .= substr($bytes, $start, $offset - $start); continue;
            }
            $scan = false;
            if ($marker === 0xD9) {
                if (!$sawScan || $offset !== $length) self::invalid();
                return $out . "\xFF\xD9";
            }
            if ($marker === 0 || $marker === 0xD8 || ($marker >= 0xD0 && $marker <= 0xD7) || $marker === 1) self::invalid();
            if ($offset + 2 > $length) self::invalid();
            $segmentLength = unpack('n', substr($bytes, $offset, 2))[1];
            if ($segmentLength < 2 || $offset + $segmentLength > $length) self::invalid();
            if (!(($marker >= 0xE0 && $marker <= 0xEF) || $marker === 0xFE)) {
                $out .= "\xFF" . chr($marker) . substr($bytes, $offset, $segmentLength);
            }
            $offset += $segmentLength;
            if ($marker === 0xDA) { $scan = true; $sawScan = true; }
        }
        self::invalid();
    }

    private static function invalid(): never
    {
        throw new HttpException(400, 'Usá una foto JPEG válida y reducida desde la app.',
            ['fotoUrl' => 'JPEG de hasta 400 KiB y 2048 × 2048 píxeles.'], errorCode: 'INVALID_PHOTO');
    }
}
