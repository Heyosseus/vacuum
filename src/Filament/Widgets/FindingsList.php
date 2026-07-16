<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Filament\Widgets;

use Filament\Widgets\Widget;
use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Severity;
use Heyosseus\Vacuum\Filament\Support\PanelData;
use Heyosseus\Vacuum\Filament\Support\SeverityColor;
use Heyosseus\Vacuum\History\FindingView;
use Heyosseus\Vacuum\History\Trend;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;
use Override;

/**
 * The findings themselves, worst first: what is wrong, what it costs, and -- where a
 * single statement would put it right -- that statement, shown and never run. This is the
 * heart of the page; the charts above it are only its shape.
 */
final class FindingsList extends Widget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    /**
     * The view is resolved here rather than through the typed `$view` property: Vacuum's
     * view namespace is registered at runtime, which the property's view-string type
     * cannot see, so the widget names its own template instead.
     */
    #[Override]
    public function render(): View
    {
        return ViewFactory::make('vacuum::filament.widgets.findings-list', $this->getViewData());
    }

    /**
     * @return list<Finding>
     */
    public function findings(): array
    {
        return app(PanelData::class)->findings();
    }

    /**
     * The same findings with history's reading laid over them, in the same order.
     *
     * @return list<FindingView>
     */
    public function findingViews(): array
    {
        return app(PanelData::class)->findingViews();
    }

    /**
     * How a finding's number is moving, as a word and a colour, or null when history
     * has nothing to say — off, too new, or a finding that does not trend. Rising is
     * the worsening direction for every metric a finding carries, so it reads red.
     *
     * @return array{symbol: string, label: string, color: string}|null
     */
    public function trend(FindingView $view): ?array
    {
        return match ($view->trend) {
            Trend::Rising => ['symbol' => '▲', 'label' => 'rising', 'color' => '#e11d48'],
            Trend::Falling => ['symbol' => '▼', 'label' => 'easing', 'color' => '#059669'],
            default => null,
        };
    }

    /**
     * The forecast in a sentence, or null when none can honestly be drawn.
     */
    public function forecast(FindingView $view): ?string
    {
        if (! $view->forecast instanceof \Heyosseus\Vacuum\History\Forecast) {
            return null;
        }

        if ($view->forecast->days <= 0) {
            return 'Projected to cross critical imminently at the current rate.';
        }

        return "Projected to cross critical in about {$view->forecast->days} days at the current rate.";
    }

    /**
     * How many findings sit at each severity, for the triage line above the list. The
     * order is the order they are read: worst first.
     *
     * @return array<int, array{severity: Severity, label: string, count: int, rail: string}>
     */
    public function triage(): array
    {
        $findings = $this->findings();

        return array_values(array_filter(
            array_map(
                fn (Severity $severity): array => [
                    'severity' => $severity,
                    'label' => $this->label($severity),
                    'count' => count(array_filter($findings, static fn (Finding $finding): bool => $finding->severity === $severity)),
                    'rail' => $this->railFor($severity),
                ],
                [Severity::Critical, Severity::Warning, Severity::Info],
            ),
            static fn (array $band): bool => $band['count'] > 0,
        ));
    }

    /** The Filament colour for a finding's severity, so the view stays about layout. */
    public function color(Finding $finding): string
    {
        return SeverityColor::for($finding->severity);
    }

    /** The severity in a word, capitalised for a heading rather than a slug. */
    public function label(Severity $severity): string
    {
        return match ($severity) {
            Severity::Critical => 'Critical',
            Severity::Warning => 'Warning',
            Severity::Info => 'Info',
        };
    }

    /**
     * The rail colour for a finding: the one saturated accent each case carries down its
     * left edge, so the eye triages by colour before it reads a word. A literal hex
     * rather than a utility class, so it survives whatever the panel's stylesheet chose
     * to compile and reads the same in either theme.
     */
    public function rail(Finding $finding): string
    {
        return $this->railFor($finding->severity);
    }

    private function railFor(Severity $severity): string
    {
        return match ($severity) {
            Severity::Critical => '#e11d48',
            Severity::Warning => '#f59e0b',
            Severity::Info => '#94a3b8',
        };
    }
}
