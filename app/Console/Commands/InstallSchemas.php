<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class InstallSchemas extends Command
{
    protected $signature = 'schema:install {--force : Re-download jars even if already present}';

    protected $description = 'Download Saxon-HE + xmlresolver jars from Maven Central into resources/schemas/saxon.';

    private const MAVEN_BASE = 'https://repo1.maven.org/maven2';

    /**
     * @var list<array{name: string, path: string}>
     */
    private const ARTIFACTS = [
        ['name' => 'Saxon-HE-12.5.jar', 'path' => 'net/sf/saxon/Saxon-HE/12.5'],
        ['name' => 'xmlresolver-5.2.2.jar', 'path' => 'org/xmlresolver/xmlresolver/5.2.2'],
        ['name' => 'xmlresolver-5.2.2-data.jar', 'path' => 'org/xmlresolver/xmlresolver/5.2.2'],
    ];

    public function handle(): int
    {
        $saxon = resource_path('schemas/saxon');
        File::ensureDirectoryExists($saxon);

        foreach (self::ARTIFACTS as $artifact) {
            $target = $saxon.'/'.$artifact['name'];

            if (is_file($target) && ! $this->option('force')) {
                $this->line("  {$artifact['name']} already present, skipping (use --force to re-download)");

                continue;
            }

            $url = self::MAVEN_BASE.'/'.$artifact['path'].'/'.$artifact['name'];
            $this->info("Downloading {$artifact['name']}");

            $response = Http::timeout(60)->sink($target)->get($url);
            if (! $response->successful()) {
                $this->error("  HTTP {$response->status()} fetching $url");
                @unlink($target);

                return self::FAILURE;
            }

            $expected = trim(Http::timeout(15)->get($url.'.sha1')->body());
            $actual = sha1_file($target) ?: '';

            if ($expected !== $actual) {
                $this->error("  SHA-1 mismatch! expected=$expected actual=$actual");
                @unlink($target);

                return self::FAILURE;
            }

            $this->line('  → '.number_format((int) filesize($target)).' bytes, SHA-1 '.$actual);
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
