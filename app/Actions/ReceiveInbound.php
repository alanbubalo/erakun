<?php

namespace App\Actions;

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceValidationException;
use App\Exceptions\SignatureVerificationException;
use App\Models\Invoice;
use App\Models\Party;
use App\Validation\ValidationIssue;
use App\Validation\ValidationReport;
use DOMDocument;
use Illuminate\Support\Facades\DB;

final readonly class ReceiveInbound
{
    public function __construct(
        private StoreInvoiceUbl $storeUbl,
        private SignatureVerifier $signatureVerifier,
    ) {}

    public function execute(ParsedInvoice $parsed, string $rawXml): Invoice
    {
        $this->assertSignatureValid($rawXml);

        return DB::transaction(function () use ($parsed, $rawXml) {
            $supplier = Party::firstOrCreate(
                ['oib' => $parsed->supplier->oib],
                [
                    'name' => $parsed->supplier->name,
                    'is_vat_registered' => $parsed->supplier->isVatRegistered,
                    'address_line' => $parsed->supplier->addressLine,
                    'city' => $parsed->supplier->city,
                    'postcode' => $parsed->supplier->postcode,
                    'country_code' => $parsed->supplier->countryCode,
                    'iban' => $parsed->supplier->iban,
                ],
            );

            $buyer = Party::where('oib', $parsed->buyer->oib)->first();
            if ($buyer === null) {
                throw new InvoiceValidationException(new ValidationReport([
                    new ValidationIssue(
                        source: 'business',
                        rule: 'buyer-not-registered',
                        severity: 'error',
                        message: "Buyer OIB {$parsed->buyer->oib} is not registered with this intermediary.",
                    ),
                ]));
            }

            $existing = Invoice::where('supplier_id', $supplier->id)
                ->where('buyer_id', $buyer->id)
                ->where('invoice_number', $parsed->invoiceNumber)
                ->where('direction', InvoiceDirection::Inbound)
                ->first();

            if ($existing !== null) {
                return $existing->load('supplier', 'buyer', 'lines');
            }

            $invoice = new Invoice([
                'supplier_id' => $supplier->id,
                'buyer_id' => $buyer->id,
                'invoice_number' => $parsed->invoiceNumber,
                'issue_date' => $parsed->issueDate,
                'due_date' => $parsed->dueDate,
                'direction' => InvoiceDirection::Inbound,
                'currency' => $parsed->currency,
                'net_amount' => $parsed->netAmount,
                'tax_amount' => $parsed->taxAmount,
                'total_amount' => $parsed->totalAmount,
            ]);

            // status is intentionally not mass-assignable; Received is the
            // birth state for an inbound invoice.
            $invoice->status = InvoiceStatus::Received;
            $invoice->save();

            $this->storeUbl->execute($invoice, $rawXml);

            foreach ($parsed->lines as $line) {
                $invoice->lines()->create([
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unitPrice,
                    'line_total' => $line->lineTotal,
                    'vat_rate' => $line->vatRate,
                    'vat_category' => $line->vatCategory,
                    'unit_code' => $line->unitCode,
                    'kpd_code' => $line->kpdCode,
                ]);
            }

            return $invoice->load('supplier', 'buyer', 'lines');
        });
    }

    /**
     * An inbound document must carry a verifiable signature: real digest match
     * (no tampering) from a certificate that chains to a trusted CA. A failure
     * surfaces as a validation error, so the direct endpoint returns 422 and the
     * AS4 path maps it to EBMS:0004.
     */
    private function assertSignatureValid(string $rawXml): void
    {
        $dom = new DOMDocument;

        $failure = static fn (string $message): InvoiceValidationException => new InvoiceValidationException(new ValidationReport([
            new ValidationIssue(
                source: 'signature',
                rule: 'signature-verification',
                severity: 'error',
                message: $message,
            ),
        ]));

        if (! $dom->loadXML($rawXml)) {
            throw $failure('Inbound document is not well-formed XML.');
        }

        try {
            $this->signatureVerifier->verify($dom);
        } catch (SignatureVerificationException $e) {
            throw $failure($e->getMessage());
        }
    }
}
