<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Party;
use Illuminate\Console\Command;
use Override;

class ExportPartyP12 extends Command
{
    #[Override]
    protected $signature = 'pki:export-p12
        {oib : OIB of the party whose active signing certificate to export}
        {--password= : Passphrase for the .p12 (default: test123)}
        {--out= : Output path (default: the party PKI folder, gitignored)}';

    #[Override]
    protected $description = 'Bundle a party\'s active signing certificate + key into a PKCS#12 (.p12) for upload (e.g. via the Bruno "Upload Certificate" request).';

    public function handle(): int
    {
        $oib = (string) $this->argument('oib');
        $password = (string) ($this->option('password') ?? 'test123');

        $party = Party::query()->where('oib', $oib)->first();
        if (! $party instanceof Party) {
            $this->error("No party with OIB {$oib}.");

            return self::FAILURE;
        }

        $certificate = $party->certificates()->active()->latest()->first();
        if ($certificate === null) {
            $this->error("Party {$oib} has no active signing certificate. Run `php artisan pki:generate --parties`.");

            return self::FAILURE;
        }

        $credential = $certificate->toSigningCredential();

        if (! openssl_pkcs12_export($credential->certificatePem, $p12, $credential->privateKeyPem, $password)) {
            $this->error('PKCS#12 export failed: '.openssl_error_string());

            return self::FAILURE;
        }

        $out = (string) ($this->option('out')
            ?? storage_path("app/private/pki/parties/{$oib}/{$oib}.p12"));

        if (file_put_contents($out, $p12) === false) {
            $this->error("Could not write {$out}.");

            return self::FAILURE;
        }

        $this->info("Created: {$out}");
        $this->line("Password: {$password}");

        return self::SUCCESS;
    }
}
