<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Resources\StatementResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Heyosseus\Vacuum\Filament\Resources\StatementResource;

/**
 * The list of statements. The columns and sorting live on the resource; the page is only
 * the seam Filament routes to.
 */
final class ListStatements extends ListRecords
{
    protected static string $resource = StatementResource::class;
}
