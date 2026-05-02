<?php

namespace App\Validation;

final class ValidationIssue
{
    public function __construct(
        public readonly string $source,
        public readonly string $rule,
        public readonly string $severity,
        public readonly string $message,
        public readonly ?string $location = null,
    ) {}

    /**
     * @return array{source: string, rule: string, severity: string, message: string, location: ?string}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'rule' => $this->rule,
            'severity' => $this->severity,
            'message' => $this->message,
            'location' => $this->location,
        ];
    }
}
