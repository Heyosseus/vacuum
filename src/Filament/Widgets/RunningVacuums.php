<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Widgets;

use Filament\Widgets\Widget;
use Heyosseus\Vacuum\Queries\RunningVacuums as RunningVacuumsQuery;
use Heyosseus\Vacuum\Values\RunningVacuum;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;
use Override;

/**
 * The vacuums PostgreSQL is running at this moment, with a progress bar where it is in a
 * phase that counts blocks and none where it is not -- a bar that invents a number for a
 * phase with no denominator is a bar that lies. Nothing here is a finding: it is the
 * database keeping the promise the rest of the page is asking it to keep, and a table
 * with ten million dead tuples reads differently when you can see it being reclaimed.
 *
 * The widget polls, because the whole point of it is to watch something move.
 */
final class RunningVacuums extends Widget
{
    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return list<RunningVacuum>
     */
    public function vacuums(): array
    {
        return app(RunningVacuumsQuery::class)->all();
    }

    /**
     * Resolved through the facade rather than the typed `$view` property, for the same
     * reason as FindingsList: the view-string type cannot see a runtime-registered
     * namespace, and this is the idiom the rest of the package already uses.
     */
    #[Override]
    public function render(): View
    {
        return ViewFactory::make('vacuum::filament.widgets.running-vacuums', $this->getViewData());
    }
}
