<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Advisor;

enum Grade: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';
    case F = 'F';

    public static function for(int $score): self
    {
        return match (true) {
            $score >= 90 => self::A,
            $score >= 80 => self::B,
            $score >= 70 => self::C,
            $score >= 60 => self::D,
            default => self::F,
        };
    }

    /**
     * This grade, or the ceiling if this grade is better than it. A ceiling can
     * only ever push a grade down: it never rescues a failing one.
     */
    public function noBetterThan(self $ceiling): self
    {
        return $this->standing() > $ceiling->standing() ? $ceiling : $this;
    }

    private function standing(): int
    {
        return match ($this) {
            self::A => 4,
            self::B => 3,
            self::C => 2,
            self::D => 1,
            self::F => 0,
        };
    }
}
