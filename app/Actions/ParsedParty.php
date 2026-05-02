<?php

declare(strict_types=1);

namespace App\Actions;

final readonly class ParsedParty
{
    public function __construct(
        public string $oib,
        public string $name,
        public string $addressLine,
        public string $city,
        public string $postcode,
        public string $countryCode,
        public bool $isVatRegistered,
        public ?string $iban = null,
    ) {}
}
