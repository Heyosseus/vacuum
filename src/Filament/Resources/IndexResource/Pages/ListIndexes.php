<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Resources\IndexResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Heyosseus\Vacuum\Filament\Resources\IndexResource;

/**
 * The list of indexes. The columns, sorting and filters live on the resource; the page
 * is only the seam Filament routes to.
 */
final class ListIndexes extends ListRecords
{
    protected static string $resource = IndexResource::class;
}
