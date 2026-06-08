<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\IssuePartyCertificate;
use App\Models\Party;
use App\Pki\TestPkiGenerator;
use Illuminate\Console\Command;
use Override;

class GeneratePki extends Command
{
    #[Override]
    protected $signature = 'pki:generate
        {--force : Regenerate the CAs and access point cert even if they already exist}
        {--parties : Also issue an active signing certificate for every party without one}';

    #[Override]
    protected $description = 'Generate the test PKI: two root CAs (FINA/OpenPEPPOL stand-ins) and the access point certificate.';

    public function handle(TestPkiGenerator $generator, IssuePartyCertificate $issuePartyCertificate): int
    {
        $force = (bool) $this->option('force');

        $this->info($force ? 'Regenerating test PKI…' : 'Ensuring test PKI exists…');
        $generator->generate($force);
        $this->line('  → root CAs and access point certificate ready on the PKI disk');

        if ($this->option('parties')) {
            $issued = 0;
            foreach (Party::query()->whereDoesntHave('certificates', fn ($q) => $q->where('status', 'active'))->cursor() as $party) {
                $issuePartyCertificate->execute($party);
                $issued++;
                $this->line("  → issued signing certificate for {$party->oib} ({$party->name})");
            }
            $this->line("  {$issued} party certificate(s) issued");
        }

        $this->info('Done. Private keys are gitignored under storage/app/private/pki.');

        return self::SUCCESS;
    }
}
