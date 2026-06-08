<?php

namespace App\Models;

use App\Enums\As4MessageDirection;
use App\Enums\FiscalMessageType;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Exceptions\InvalidInvoiceTransitionException;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Override;

/**
 * @property int $id
 * @property Carbon $issue_date
 * @property Carbon|null $due_date
 * @property InvoiceStatus $status
 * @property InvoiceDirection $direction
 * @property string $currency
 * @property string $net_amount
 * @property string $tax_amount
 * @property string $total_amount
 * @property string|null $ubl_xml_path
 * @property-read string|null $ubl_xml
 */
#[Fillable([
    'supplier_id',
    'buyer_id',
    'invoice_number',
    'issue_date',
    'due_date',
    'direction',
    'currency',
    'net_amount',
    'tax_amount',
    'total_amount',
    'ubl_xml_path',
])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    #[Override]
    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'status' => InvoiceStatus::class,
            'direction' => InvoiceDirection::class,
            'net_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    /**
     * Read-only view of the signed/raw UBL document, streamed from the
     * configured filesystem disk. Only a path is persisted in the DB
     * (see the StoreInvoiceUbl action); never eager-select this
     * in list queries.
     */
    protected function ublXml(): Attribute
    {
        return Attribute::get(function (): ?string {
            if ($this->ubl_xml_path === null) {
                return null;
            }

            $disk = Storage::disk();

            return $disk->exists($this->ubl_xml_path)
                ? $disk->get($this->ubl_xml_path)
                : null;
        });
    }

    public function transitionTo(InvoiceStatus $target): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw new InvalidInvoiceTransitionException($this->status, $target);
        }

        $this->status = $target;
        $this->save();
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'supplier_id');
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'buyer_id');
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /**
     * @return HasMany<FiscalMessage, $this>
     */
    public function fiscalMessages(): HasMany
    {
        return $this->hasMany(FiscalMessage::class);
    }

    /**
     * @return HasMany<As4Message, $this>
     */
    public function as4Messages(): HasMany
    {
        return $this->hasMany(As4Message::class);
    }

    public function latestAs4MessageFor(As4MessageDirection $direction): ?As4Message
    {
        return $this->as4Messages()
            ->where('direction', $direction)
            ->orderByDesc('id')
            ->first();
    }

    public function latestFiscalMessageFor(string $reporterOib): ?FiscalMessage
    {
        return $this->fiscalMessages()
            ->where('reporter_oib', $reporterOib)
            ->where('message_type', FiscalMessageType::Fis)
            ->orderByDesc('id')
            ->first();
    }

    public function reporterOib(): string
    {
        return $this->direction === InvoiceDirection::Outbound
            ? $this->supplier->oib
            : $this->buyer->oib;
    }

    /**
     * @return list<array{category: string, rate: string, taxable: string, tax: string}>
     */
    public function vatBreakdown(): array
    {
        $groups = [];

        foreach ($this->lines as $line) {
            $key = $line->vat_category->value.'|'.$line->vat_rate;

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'category' => $line->vat_category->value,
                    'rate' => $line->vat_rate,
                    'taxable' => '0.00',
                    'tax' => '0.00',
                ];
            }

            $groups[$key]['taxable'] = bcadd($groups[$key]['taxable'], (string) $line->line_total, 2);
            $groups[$key]['tax'] = bcadd(
                $groups[$key]['tax'],
                bcdiv(bcmul((string) $line->line_total, (string) $line->vat_rate, 4), '100', 2),
                2,
            );
        }

        return array_values($groups);
    }
}
