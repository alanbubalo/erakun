<?php

declare(strict_types=1);

namespace App\Validation;

final readonly class ValidationReport
{
    /**
     * @param  list<ValidationIssue>  $issues
     */
    public function __construct(public array $issues = []) {}

    public function isValid(): bool
    {
        return array_all($this->issues, fn ($issue): bool => $issue->severity !== 'error');
    }

    /**
     * @return list<array{source: string, rule: string, severity: string, message: string, location: ?string}>
     */
    public function toArray(): array
    {
        return array_map(fn (ValidationIssue $i): array => $i->toArray(), $this->issues);
    }
}
