<?php

declare(strict_types=1);

arch()->preset()->php();
arch()->preset()->security();

arch('the package ships no debugging leftovers')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die', 'exit'])
    ->not->toBeUsed();

arch('every source file declares strict types')
    ->expect('Heyosseus\Vacuum')
    ->toUseStrictTypes();

arch('source classes are final unless deliberately extended')
    ->expect('Heyosseus\Vacuum')
    ->classes()
    ->toBeFinal();
