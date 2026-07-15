<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Filament\Install\PluginRegistrar;

/**
 * The one call the registrar is trying to land, immediately before the semicolon
 * that ends the panel's return statement. If it sits anywhere else the edit is
 * wrong, so every test looks for exactly this shape.
 */
function pluginCallBeforeSemicolon(): string
{
    return '/->plugin\(\\\\Heyosseus\\\\Vacuum\\\\Filament\\\\VacuumPlugin::make\(\)\)\s*;/';
}

function providerWith(string $chain): string
{
    return <<<PHP
    <?php

    namespace App\\Providers\\Filament;

    use Filament\\Panel;
    use Filament\\PanelProvider;

    class AdminPanelProvider extends PanelProvider
    {
        public function panel(Panel \$panel): Panel
        {
            return \$panel
    {$chain};
        }
    }
    PHP;
}

it('lands the plugin call just before the semicolon of a multi-line chain', function (): void {
    $source = providerWith("            ->default()\n            ->id('admin')\n            ->path('admin')");

    $result = (new PluginRegistrar)->inject($source);

    expect($result)->not->toBeNull()
        ->and($result)->toMatch(pluginCallBeforeSemicolon())
        // Exactly once: a second run must not double it, and neither must a chain
        // that already mentions other plugins.
        ->and(substr_count((string) $result, '->plugin(\Heyosseus\Vacuum\Filament\VacuumPlugin::make())'))->toBe(1);
});

it('lands the plugin call on a single-line chain', function (): void {
    $source = providerWith("->default()->id('admin')");

    $result = (new PluginRegistrar)->inject($source);

    expect($result)->not->toBeNull()
        ->and($result)->toMatch(pluginCallBeforeSemicolon());
});

it('is not fooled by a plugins array already in the chain', function (): void {
    // The array holds its own semicolon-free brackets and commas; the terminator we
    // want is the one that ends the whole return statement, after the array closes.
    $chain = "            ->default()\n"
        ."            ->plugins([\n"
        ."                SomeOtherPlugin::make(),\n"
        .'            ])';
    $source = providerWith($chain);

    $result = (new PluginRegistrar)->inject($source);

    expect($result)->not->toBeNull()
        ->and($result)->toMatch(pluginCallBeforeSemicolon())
        ->and($result)->toContain('SomeOtherPlugin::make()');
});

it('leaves a provider that already registers vacuum untouched', function (): void {
    $source = providerWith("            ->default()\n            ->plugin(\Heyosseus\Vacuum\Filament\VacuumPlugin::make())");

    $result = (new PluginRegistrar)->inject($source);

    expect($result)->toBe($source)
        ->and(substr_count((string) $result, 'VacuumPlugin'))->toBe(1);
});

it('refuses to edit a shape it cannot recognise', function (): void {
    // No `return $panel` chain to hook onto. Returning null is the whole point: the
    // caller prints instructions rather than writing a guess into someone's app.
    $source = <<<'PHP'
    <?php

    namespace App\Providers\Filament;

    class AdminPanelProvider
    {
        // configured somewhere this registrar was never meant to parse
    }
    PHP;

    expect((new PluginRegistrar)->inject($source))->toBeNull();
});
