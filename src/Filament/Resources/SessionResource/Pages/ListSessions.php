<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Resources\SessionResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Heyosseus\Vacuum\Filament\Resources\SessionResource;

/**
 * The list of live sessions. The columns, polling and filters live on the resource; the
 * page is only the seam Filament routes to.
 */
final class ListSessions extends ListRecords
{
    protected static string $resource = SessionResource::class;
}
