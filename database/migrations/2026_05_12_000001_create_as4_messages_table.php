<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('as4_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->nullable()->constrained();
            $table->string('direction');
            $table->string('message_id', 64);
            $table->string('ref_to_message_id', 64)->nullable();
            $table->string('from_oib', 11);
            $table->string('to_oib', 11);
            $table->string('state');
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->text('envelope_xml');
            $table->text('receipt_xml')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            // A single AS4 message legitimately produces two rows in a loopback
            // intermediary: the sender's outbound copy and the receiver's
            // inbound copy, which share the same ebMS MessageId. Scope
            // uniqueness by direction so the outbound row does not collide with
            // the inbound row the receiving side persists while handling it.
            $table->unique(['direction', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('as4_messages');
    }
};
