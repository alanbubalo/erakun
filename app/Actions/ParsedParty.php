<?php

namespace App\Actions;

final class ParsedParty
{
    public function __construct(
        public readonly string $oib,
        public readonly string $name,
        public readonly string $addressLine,
        public readonly string $city,
        public readonly string $postcode,
        public readonly string $countryCode,
        public readonly bool $isVatRegistered,
        public readonly ?string $iban = null,
    ) {}
}
