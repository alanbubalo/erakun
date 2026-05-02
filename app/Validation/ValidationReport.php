<?php

namespace App\Validation;

final class ValidationReport
{
    /**
     * @param  list<ValidationIssue>  $issues
     */
    public function __construct(public readonly array $issues = []) {}

    public function isValid(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->severity === 'error') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array{source: string, rule: string, severity: string, message: string, location: ?string}>
     */
    public function toArray(): array
    {
        return array_map(fn (ValidationIssue $i) => $i->toArray(), $this->issues);
    }
}
