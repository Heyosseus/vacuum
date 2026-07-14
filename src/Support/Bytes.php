<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Support;

final class Bytes
{
    /**
     * @var list<string>
     */
    private const array UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    /**
     * The size as somebody would have said it out loud.
     */
    public static function human(int $bytes): string
    {
        $size = (float) $bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count(self::UNITS) - 1) {
            $size /= 1024;
            $unit++;
        }

        if ($unit === 0) {
            return $bytes.' B';
        }

        return number_format($size, 1).' '.self::UNITS[$unit];
    }
}
