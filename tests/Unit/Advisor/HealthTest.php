<?php

declare(strict_types=1);

use Heyosseus\Vacuum\Advisor\Finding;
use Heyosseus\Vacuum\Advisor\Grade;
use Heyosseus\Vacuum\Advisor\Health;
use Heyosseus\Vacuum\Advisor\Severity;

function complaint(string $rule, Severity $severity, string $subject = 'public.widgets'): Finding
{
    return new Finding(
        rule: $rule,
        subject: $subject,
        severity: $severity,
        summary: 'summary',
        impact: 'impact',
    );
}

it('gives a database nobody has a complaint about full marks', function (): void {
    $health = Health::from([]);

    expect($health->score)->toBe(100)
        ->and($health->grade)->toBe(Grade::A)
        ->and($health->deductions)->toBe([]);
});

it('takes more off for a critical finding than for a warning', function (): void {
    expect(Health::from([complaint('a', Severity::Critical)])->score)->toBe(85)
        ->and(Health::from([complaint('a', Severity::Warning)])->score)->toBe(95);
});

it('charges nothing for something it merely wants you to know', function (): void {
    // An Info finding is a fact, not a fault. Charging for it would mean a server
    // is marked down for telling you the truth about what it cannot see.
    expect(Health::from([complaint('partial-visibility', Severity::Info)])->score)->toBe(100);
});

it('shows its arithmetic', function (): void {
    $health = Health::from([
        complaint('dead-tuples', Severity::Critical),
        complaint('unused-index', Severity::Warning),
    ]);

    expect($health->deductions)->toBe(['dead-tuples' => 15, 'unused-index' => 5])
        ->and($health->score)->toBe(80);
});

it('stops one noisy rule from swallowing the whole score', function (): void {
    // Forty bloated tables are one problem, not forty. Without a cap they would
    // bury a blocked session that is taking the application down right now.
    $findings = array_map(
        fn (int $i): Finding => complaint('table-bloat', Severity::Warning, "public.table{$i}"),
        range(1, 40),
    );

    expect(Health::from($findings)->deductions)->toBe(['table-bloat' => 25]);
});

it('never falls through the floor', function (): void {
    $findings = [];

    foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $rule) {
        $findings[] = complaint($rule, Severity::Critical);
        $findings[] = complaint($rule, Severity::Critical);
    }

    expect(Health::from($findings)->score)->toBe(0)
        ->and(Health::from($findings)->grade)->toBe(Grade::F);
});

it('refuses to call a database with a critical finding healthy', function (): void {
    // The arithmetic says 85, which is a B, and a B is a grade you scroll past.
    // A database on its way to refusing every write is not a B.
    $health = Health::from([complaint('wraparound', Severity::Critical)]);

    expect($health->score)->toBe(85)
        ->and($health->grade)->toBe(Grade::D);
});

it('leaves the arithmetic alone when it caps the grade', function (): void {
    // The cap is a judgement about the letter, not a thumb on the scale. The
    // score still has to equal a hundred minus the deductions printed beside it.
    $health = Health::from([complaint('wraparound', Severity::Critical)]);

    expect($health->score)->toBe(100 - array_sum($health->deductions));
});

it('admits when the letter is a cap rather than the arithmetic', function (): void {
    expect(Health::from([complaint('wraparound', Severity::Critical)])->capped)->toBeTrue()
        ->and(Health::from([complaint('unused-index', Severity::Warning)])->capped)->toBeFalse()
        ->and(Health::from([])->capped)->toBeFalse();
});

it('has capped nothing when the score was already below the ceiling', function (): void {
    // Twelve critical findings grade an F on the arithmetic alone. The ceiling
    // changed nothing, so the page should not claim it did.
    $findings = [];

    foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $rule) {
        $findings[] = complaint($rule, Severity::Critical);
        $findings[] = complaint($rule, Severity::Critical);
    }

    expect(Health::from($findings)->capped)->toBeFalse();
});

it('does not cap the grade for warnings, however many', function (): void {
    $findings = array_map(
        fn (int $i): Finding => complaint("rule{$i}", Severity::Warning),
        range(1, 3),
    );

    expect(Health::from($findings)->grade)->toBe(Grade::B);
});

it('does not lift a failing grade to the cap', function (): void {
    // The cap is a ceiling, not a floor: it can only ever push a grade down.
    $findings = [];

    foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $rule) {
        $findings[] = complaint($rule, Severity::Critical);
        $findings[] = complaint($rule, Severity::Critical);
    }

    expect(Health::from($findings)->grade)->toBe(Grade::F);
});

it('puts the rule costing the most at the top of the arithmetic', function (): void {
    $health = Health::from([
        complaint('unused-index', Severity::Warning),
        complaint('dead-tuples', Severity::Critical),
    ]);

    expect(array_key_first($health->deductions))->toBe('dead-tuples');
});

it('grades the score the way a school would', function (): void {
    expect(Grade::for(100))->toBe(Grade::A)
        ->and(Grade::for(90))->toBe(Grade::A)
        ->and(Grade::for(89))->toBe(Grade::B)
        ->and(Grade::for(80))->toBe(Grade::B)
        ->and(Grade::for(79))->toBe(Grade::C)
        ->and(Grade::for(70))->toBe(Grade::C)
        ->and(Grade::for(69))->toBe(Grade::D)
        ->and(Grade::for(60))->toBe(Grade::D)
        ->and(Grade::for(59))->toBe(Grade::F)
        ->and(Grade::for(0))->toBe(Grade::F);
});
