<?php

declare(strict_types=1);

use App\Enums\As4MessageDirection;
use App\Enums\As4MessageState;
use App\Models\As4Message;
use App\Models\Invoice;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

it('belongs to an invoice', function (): void {
    $invoice = Invoice::factory()->create();
    $message = As4Message::factory()->for($invoice)->create();

    expect($message->invoice->id)->toBe($invoice->id);
});

it('casts direction, state, and timestamps', function (): void {
    $message = As4Message::factory()->acknowledged()->create();

    $fresh = $message->fresh();

    expect($fresh->direction)->toBe(As4MessageDirection::Outbound)
        ->and($fresh->state)->toBe(As4MessageState::Acknowledged)
        ->and($fresh->sent_at)->toBeInstanceOf(Carbon::class)
        ->and($fresh->acknowledged_at)->toBeInstanceOf(Carbon::class);
});

it('exposes as4Messages HasMany on Invoice', function (): void {
    $invoice = Invoice::factory()->create();
    As4Message::factory()->for($invoice)->count(2)->create();

    expect($invoice->as4Messages)->toHaveCount(2);
});

it('enforces a unique message_id', function (): void {
    $invoice = Invoice::factory()->create();
    $duplicate = 'shared-id@erakun';

    As4Message::factory()->for($invoice)->create(['message_id' => $duplicate]);

    expect(fn () => As4Message::factory()->for($invoice)->create(['message_id' => $duplicate]))
        ->toThrow(QueryException::class);
});
