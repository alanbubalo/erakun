<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('active');
            $table->string('serial_number');
            $table->string('subject');
            $table->string('issuer');
            $table->timestamp('valid_from');
            $table->timestamp('valid_to');
            // The public X.509 certificate (PEM). Safe to store and return.
            $table->text('certificate_pem');
            // Path to the private key on the gitignored PKI disk — never serialised.
            $table->string('private_key_path');
            $table->string('fingerprint', 64)->index();
            $table->timestamps();

            // Drives the activeCertificate() lookup and the single-active invariant.
            $table->index(['party_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
