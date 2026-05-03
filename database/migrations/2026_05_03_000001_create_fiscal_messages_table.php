<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained();
            $table->string('reporter_oib', 11);
            $table->string('message_type');
            $table->string('state');
            $table->string('service_message_id')->nullable();
            $table->string('match_status')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->text('request_xml')->nullable();
            $table->text('response_xml')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['invoice_id', 'reporter_oib', 'message_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_messages');
    }
};
