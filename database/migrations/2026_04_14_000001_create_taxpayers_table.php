<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxpayers', function (Blueprint $table) {
            $table->id();
            $table->string('oib', 11)->unique();
            $table->string('name');
            $table->boolean('is_vat_registered')->default(false);
            $table->string('address_line');
            $table->string('city');
            $table->string('postcode', 5);
            $table->string('country_code', 2)->default('HR');
            $table->string('iban')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxpayers');
    }
};
