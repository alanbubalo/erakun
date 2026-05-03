<?php

use App\Enums\FiscalMessageState;
use App\Enums\FiscalMessageType;
use App\Enums\MatchStatus;
use App\Models\FiscalMessage;
use App\Models\Invoice;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

it('belongs to an invoice', function (): void {
    $invoice = Invoice::factory()->create();
    $message = FiscalMessage::factory()->for($invoice)->create();

    expect($message->invoice->id)->toBe($invoice->id);
});

it('casts message_type, state, and match_status to enums', function (): void {
    $message = FiscalMessage::factory()->create([
        'state' => FiscalMessageState::Requested,
        'match_status' => MatchStatus::Pending,
    ]);

    $fresh = $message->fresh();

    expect($fresh->message_type)->toBe(FiscalMessageType::Fis)
        ->and($fresh->state)->toBe(FiscalMessageState::Requested)
        ->and($fresh->match_status)->toBe(MatchStatus::Pending);
});

it('leaves match_status null when not set', function (): void {
    $message = FiscalMessage::factory()->create();

    expect($message->fresh()->match_status)->toBeNull();
});

it('casts submitted_at and settled_at to Carbon instances', function (): void {
    $message = FiscalMessage::factory()->accepted()->create();

    $fresh = $message->fresh();

    expect($fresh->submitted_at)->toBeInstanceOf(Carbon::class)
        ->and($fresh->settled_at)->toBeInstanceOf(Carbon::class);
});

it('exposes a fiscalMessages HasMany relation on Invoice', function (): void {
    $invoice = Invoice::factory()->create();
    FiscalMessage::factory()->for($invoice)->create([
        'reporter_oib' => '11111111111',
    ]);
    FiscalMessage::factory()->for($invoice)->create([
        'reporter_oib' => '22222222222',
    ]);

    expect($invoice->fiscalMessages)->toHaveCount(2);
});

it('is deleted when its invoice is deleted', function (): void {
    $invoice = Invoice::factory()->create();
    FiscalMessage::factory(2)->for($invoice)->sequence(
        ['reporter_oib' => '11111111111'],
        ['reporter_oib' => '22222222222'],
    )->create();

    $invoice->delete();

    expect(FiscalMessage::where('invoice_id', $invoice->id)->count())->toBe(0);
});

describe('latestFiscalMessageFor', function (): void {
    it('returns null when no fiscal message exists for the reporter OIB', function (): void {
        $invoice = Invoice::factory()->create();

        expect($invoice->latestFiscalMessageFor('00000000000'))->toBeNull();
    });

    it('returns the message scoped to the given reporter OIB', function (): void {
        $invoice = Invoice::factory()->create();
        $supplier = FiscalMessage::factory()->for($invoice)->create([
            'reporter_oib' => '11111111111',
        ]);
        $buyer = FiscalMessage::factory()->for($invoice)->create([
            'reporter_oib' => '22222222222',
        ]);

        expect($invoice->latestFiscalMessageFor('11111111111')->id)->toBe($supplier->id)
            ->and($invoice->latestFiscalMessageFor('22222222222')->id)->toBe($buyer->id);
    });

    it('orders by id desc so the most recent row wins', function (): void {
        $invoice = Invoice::factory()->create();
        $reporterOib = '11111111111';

        // Use a different invoice as a decoy to make sure scoping holds.
        $otherInvoice = Invoice::factory()->create();
        FiscalMessage::factory()->for($otherInvoice)->create([
            'reporter_oib' => $reporterOib,
        ]);

        $own = FiscalMessage::factory()->for($invoice)->create([
            'reporter_oib' => $reporterOib,
        ]);

        $latest = $invoice->latestFiscalMessageFor($reporterOib);

        expect($latest)->not->toBeNull()
            ->and($latest->id)->toBe($own->id);
    });
});

it('enforces unique (invoice_id, reporter_oib, message_type)', function (): void {
    $invoice = Invoice::factory()->create();
    $reporterOib = '11111111111';

    FiscalMessage::factory()->for($invoice)->create([
        'reporter_oib' => $reporterOib,
        'message_type' => FiscalMessageType::Fis,
    ]);

    expect(fn () => FiscalMessage::factory()->for($invoice)->create([
        'reporter_oib' => $reporterOib,
        'message_type' => FiscalMessageType::Fis,
    ]))->toThrow(QueryException::class);
});
