<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('taxpayers');
            $table->foreignId('buyer_id')->constrained('taxpayers');
            $table->string('invoice_number');
            $table->date('issue_date');
            $table->string('status');
            $table->string('direction');
            $table->decimal('total_amount', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
