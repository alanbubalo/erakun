<?php

declare(strict_types=1);

namespace App\As4;

interface PeerEndpointResolver
{
    public function resolve(string $recipientOib): ?string;
}
