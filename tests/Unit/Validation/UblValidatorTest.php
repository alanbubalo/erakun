<?php

use App\Validation\UblValidator;
use App\Validation\ValidationReport;

function loadFixture(string $name): string
{
    $path = __DIR__.'/../../Fixtures/ubl/'.$name;
    $xml = file_get_contents($path);

    expect($xml)->toBeString()->not->toBeEmpty();

    return $xml;
}

function ruleIds(ValidationReport $report): array
{
    return array_map(fn ($i) => $i->rule, $report->issues);
}

it('passes the canonical valid HR-CIUS fixture with zero issues', function () {
    $report = app(UblValidator::class)->validate(loadFixture('valid-hr-cius.xml'));

    expect($report->issues)->toBe([]);
    expect($report->isValid())->toBeTrue();
});

it('reports BR-CO-10 on the invalid fixture (line sums disagree with monetary total)', function () {
    $report = app(UblValidator::class)->validate(loadFixture('invalid-br-co-10.xml'));

    expect($report->isValid())->toBeFalse();
    expect(ruleIds($report))->toContain('BR-CO-10');

    $brCo10 = collect($report->issues)->firstWhere('rule', 'BR-CO-10');
    expect($brCo10->source)->toBe('schematron')
        ->and($brCo10->severity)->toBe('error')
        ->and($brCo10->message)->not->toBeEmpty();
});
