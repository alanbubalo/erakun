<?php

declare(strict_types=1);

namespace Tests\Doubles;

use App\Enums\MatchStatus;
use App\Fiscalization\FiscalizationResponse;
use App\Fiscalization\FiscalizationService;
use App\Fiscalization\FiscalizationServiceException;
use App\Fiscalization\MatchReport;
use App\Fiscalization\SubmissionSnapshot;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;

/**
 * Stateful in-memory test double that imitates the matching behaviour of a real
 * fiscalization service. Submissions are keyed by (supplier_oib, buyer_oib,
 * invoice_number); when both supplier-side and buyer-side reports land for the
 * same key, the service flips its match state based on amount comparison.
 */
final class InMemoryFiscalizationService implements FiscalizationService
{
    private const string NS = 'urn:hr:erakun:fiscal:1.0';

    /**
     * @var array<string, array{
     *   match_status: MatchStatus,
     *   mismatch_fields: list<string>,
     *   supplier: ?SubmissionSnapshot,
     *   buyer: ?SubmissionSnapshot,
     * }>
     */
    private array $records = [];

    private int $sequence = 0;

    public function fiscalize(string $signedRequestXml): FiscalizationResponse
    {
        $extract = $this->extract($signedRequestXml);
        $key = $this->key($extract['supplier_oib'], $extract['buyer_oib'], $extract['invoice_number']);
        $record = $this->records[$key] ?? $this->emptyRecord();

        $messageId = sprintf('FIS-FAKE-%06d', ++$this->sequence);
        $receivedAt = CarbonImmutable::now();
        $snapshot = new SubmissionSnapshot(
            messageId: $messageId,
            reporterOib: $extract['reporter_oib'],
            receivedAt: $receivedAt,
            netAmount: $extract['net_amount'],
            taxAmount: $extract['tax_amount'],
            totalAmount: $extract['total_amount'],
        );

        $role = $extract['reporter_oib'] === $extract['supplier_oib'] ? 'supplier' : 'buyer';
        $record[$role] = $snapshot;

        if ($record['supplier'] !== null && $record['buyer'] !== null) {
            $mismatch = $this->compare($record['supplier'], $record['buyer']);
            $record['match_status'] = $mismatch === [] ? MatchStatus::Matched : MatchStatus::Mismatch;
            $record['mismatch_fields'] = $mismatch;
        } else {
            $record['match_status'] = MatchStatus::Pending;
            $record['mismatch_fields'] = [];
        }

        $this->records[$key] = $record;

        return new FiscalizationResponse(
            messageId: $messageId,
            receivedAt: $receivedAt,
            matchStatus: $record['match_status'],
        );
    }

    public function lookupMatch(string $supplierOib, string $buyerOib, string $invoiceNumber): MatchReport
    {
        $record = $this->records[$this->key($supplierOib, $buyerOib, $invoiceNumber)] ?? $this->emptyRecord();

        return new MatchReport(
            supplierSubmission: $record['supplier'],
            buyerSubmission: $record['buyer'],
            matchStatus: $record['match_status'],
            mismatchFields: $record['mismatch_fields'],
        );
    }

    /**
     * @return array{
     *   reporter_oib: string,
     *   supplier_oib: string,
     *   buyer_oib: string,
     *   invoice_number: string,
     *   net_amount: string,
     *   tax_amount: string,
     *   total_amount: string,
     * }
     */
    private function extract(string $xml): array
    {
        $dom = new DOMDocument;

        throw_unless($dom->loadXML($xml), FiscalizationServiceException::class, 'XSD_INVALID', 'Could not parse fiscalization request XML.');

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('f', self::NS);

        $get = function (string $expr) use ($xpath): string {
            $node = $xpath->query('/f:FiscalizationRequest/'.$expr)->item(0);

            return $node === null ? '' : trim((string) $node->textContent);
        };

        return [
            'reporter_oib' => $get('f:Reporter/f:Oib'),
            'supplier_oib' => $get('f:Supplier/f:Oib'),
            'buyer_oib' => $get('f:Buyer/f:Oib'),
            'invoice_number' => $get('f:Invoice/f:Number'),
            'net_amount' => $get('f:Invoice/f:NetAmount'),
            'tax_amount' => $get('f:Invoice/f:TaxAmount'),
            'total_amount' => $get('f:Invoice/f:TotalAmount'),
        ];
    }

    /**
     * @return list<string>
     */
    private function compare(SubmissionSnapshot $a, SubmissionSnapshot $b): array
    {
        $diff = [];

        if ($a->netAmount !== $b->netAmount) {
            $diff[] = 'net_amount';
        }

        if ($a->taxAmount !== $b->taxAmount) {
            $diff[] = 'tax_amount';
        }

        if ($a->totalAmount !== $b->totalAmount) {
            $diff[] = 'total_amount';
        }

        return $diff;
    }

    private function key(string $supplierOib, string $buyerOib, string $invoiceNumber): string
    {
        return $supplierOib.'|'.$buyerOib.'|'.$invoiceNumber;
    }

    /**
     * @return array{
     *   match_status: MatchStatus,
     *   mismatch_fields: list<string>,
     *   supplier: ?SubmissionSnapshot,
     *   buyer: ?SubmissionSnapshot,
     * }
     */
    private function emptyRecord(): array
    {
        return [
            'match_status' => MatchStatus::Pending,
            'mismatch_fields' => [],
            'supplier' => null,
            'buyer' => null,
        ];
    }
}
