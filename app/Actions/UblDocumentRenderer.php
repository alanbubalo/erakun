<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\InvoiceValidationException;
use App\Models\Invoice;
use App\Pki\PartySigningCredentials;
use App\Validation\UblValidator;
use RuntimeException;

/**
 * Renders an Invoice into its UBL document, in the two forms the system needs:
 * an unsigned {@see draft()} for on-the-fly preview, and the {@see signed()}
 * final document that the lifecycle persists and transmits.
 *
 * This module is the single owner of "how an Invoice becomes UBL". The signed
 * path chains generate → sign → serialise → validate, and the byte-fidelity
 * contract that path depends on is an internal implementation detail here, not a
 * social contract spread across callers: the signature is computed over the
 * in-memory DOM, so the returned bytes are the verbatim serialisation of the
 * signed document. Callers persist those bytes unchanged; re-serialising or
 * pretty-printing them would break verification (see InvoiceSigner). The module
 * never stores — it returns bytes and leaves persistence to the caller.
 */
final readonly class UblDocumentRenderer
{
    public function __construct(
        private UblGenerator $generator,
        private InvoiceSigner $signer,
        private UblValidator $validator,
        private PartySigningCredentials $signingCredentials,
    ) {}

    /**
     * Unsigned, unvalidated UBL — a preview of how the invoice maps to UBL.
     */
    public function draft(Invoice $invoice): string
    {
        $xml = $this->generator->execute($invoice)->saveXML();

        throw_if($xml === false, RuntimeException::class, 'Failed to serialise UBL document.');

        return $xml;
    }

    /**
     * The signed, validated UBL document, returned as the exact bytes that were
     * signed. Throws {@see InvoiceValidationException} if the document fails
     * XSD/schematron validation, leaving the caller free to abort before storing.
     */
    public function signed(Invoice $invoice): string
    {
        $dom = $this->generator->execute($invoice);
        $signed = $this->signer->execute($dom, $this->signingCredentials->for($invoice->supplier));
        $xml = $signed->saveXML();

        throw_if($xml === false, RuntimeException::class, 'Failed to serialise signed UBL document.');

        $report = $this->validator->validate($xml);
        throw_unless($report->isValid(), InvoiceValidationException::class, $report);

        return $xml;
    }
}
