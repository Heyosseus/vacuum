<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Resources\TableResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Heyosseus\Vacuum\Filament\Resources\TableResource;

/**
 * One table, drilled into. The infolist it renders is declared on the resource and
 * fed by the profile the bound record resolves, so this page too is only a seam.
 */
final class ViewTable extends ViewRecord
{
    protected static string $resource = TableResource::class;
}
