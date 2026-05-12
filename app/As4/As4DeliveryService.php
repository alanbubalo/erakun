<?php

declare(strict_types=1);

namespace App\As4;

interface As4DeliveryService
{
    /**
     * Wrap, sign, and POST a UBL invoice to the peer AP serving the recipient OIB.
     *
     * @throws As4DeliveryException on envelope/peer/transport failure
     */
    public function send(
        string $ublXml,
        string $senderOib,
        string $recipientOib,
    ): As4DeliveryReceipt;
}
