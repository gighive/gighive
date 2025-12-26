<?php declare(strict_types=1);

namespace Production\Api\Config;

final class MediaTypes
{
    /** @return string[] */
    public static function allowedMimes(): array
    {
        $v = self::jsonEnvArray('UPLOAD_ALLOWED_MIMES_JSON');
        if ($v !== null) {
            return self::normalizeLowerTrim($v);
        }

        return [];
    }

    /** @return string[] */
    public static function audioExts(): array
    {
        $v = self::jsonEnvArray('UPLOAD_AUDIO_EXTS_JSON');
        if ($v !== null) {
            return self::normalizeLowerTrim($v);
        }

        return [];
    }

    /** @return string[] */
    public static function videoExts(): array
    {
        $v = self::jsonEnvArray('UPLOAD_VIDEO_EXTS_JSON');
        if ($v !== null) {
            return self::normalizeLowerTrim($v);
        }

        return [];
    }

    /** @return string[]|null */
    private static function jsonEnvArray(string $key): ?array
    {
        $raw = getenv($key);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $out = [];
        foreach ($decoded as $item) {
            if (is_string($item)) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /** @param string[] $items @return string[] */
    private static function normalizeLowerTrim(array $items): array
    {
        $out = [];
        foreach ($items as $x) {
            $x = strtolower(trim($x));
            if ($x === '') {
                continue;
            }
            $out[$x] = true;
        }
        return array_keys($out);
    }
}
