<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained('parties');
            $table->foreignId('buyer_id')->constrained('parties');
            $table->string('invoice_number');
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->string('status');
            $table->string('direction');
            $table->string('currency', 3)->default('EUR');
            $table->decimal('net_amount', 12, 2);
            $table->decimal('tax_amount', 12, 2);
            $table->decimal('total_amount', 12, 2);
            $table->string('ubl_xml_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
