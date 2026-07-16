<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Widgets;

use Filament\Widgets\Widget;
use Heyosseus\Vacuum\Filament\Support\HistoryPanel;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;
use Override;

/**
 * The History page's one widget: the health line over time, and beneath it what is
 * newly wrong, what has cleared, and what is forecast to break.
 */
final class HealthTimeline extends Widget
{
    protected int|string|array $columnSpan = 'full';

    /**
     * Resolved here rather than through the typed `$view` property: Vacuum registers
     * its view namespace at runtime, which that property's type cannot see.
     */
    #[Override]
    public function render(): View
    {
        return ViewFactory::make('vacuum::filament.widgets.health-timeline');
    }

    public function panel(): HistoryPanel
    {
        return app(HistoryPanel::class);
    }
}
