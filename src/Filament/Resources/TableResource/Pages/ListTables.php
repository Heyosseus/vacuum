<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Resources\TableResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Heyosseus\Vacuum\Filament\Resources\TableResource;

/**
 * The list of tables. Everything it does -- the columns, the sorting, the drill-in
 * -- is declared on the resource, so the page is only the seam Filament routes to.
 */
final class ListTables extends ListRecords
{
    protected static string $resource = TableResource::class;
}
