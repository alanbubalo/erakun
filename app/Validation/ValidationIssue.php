<?php

declare(strict_types=1);

namespace App\Validation;

final readonly class ValidationIssue
{
    public function __construct(
        public string $source,
        public string $rule,
        public string $severity,
        public string $message,
        public ?string $location = null,
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
