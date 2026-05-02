<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class CompileSchematron extends Command
{
    protected $signature = 'schema:compile';

    protected $description = 'Compile vendored Schematron rule sets to runtime XSLT 2.0 validators via Saxon-HE.';

    /**
     * Pipeline (per .sch input): iso_dsdl_include → iso_abstract_expand → iso_svrl_for_xslt2.
     */
    public function handle(): int
    {
        $schemas = resource_path('schemas');
        $skeleton = $schemas.'/skeleton';
        $compiled = $schemas.'/compiled';

        $classpath = implode(':', [
            $schemas.'/saxon/Saxon-HE-12.5.jar',
            $schemas.'/saxon/xmlresolver-5.2.2.jar',
            $schemas.'/saxon/xmlresolver-5.2.2-data.jar',
        ]);

        File::ensureDirectoryExists($compiled);

        // EN 16931's UBL syntax pattern (UBL-CR-*) bans constructs that HR-CIUS extensions
        // legitimately require (cac:SellerContact, cbc:IssueTime, ext:UBLExtensions). We compile
        // only the model phase so BR-* business rules fire while syntax restrictions are skipped.
        // HR-CIUS bundles its own codelists, so the codelist phase is left to it.
        $jobs = [
            'en16931' => [$schemas.'/en16931/EN16931-UBL-validation.sch', 'EN16931model_phase'],
            'hr-cius' => [$schemas.'/hr-cius/HR-CIUS-EXT-EN16931-UBL.sch', null],
        ];

        $tmp = storage_path('app/schema-compile-'.getmypid());
        File::ensureDirectoryExists($tmp);

        try {
            foreach ($jobs as $name => [$sch, $phase]) {
                if (! is_file($sch)) {
                    $this->error("Missing input: $sch");

                    return self::FAILURE;
                }

                $this->info("Compiling $name from ".basename($sch).($phase ? " (phase=$phase)" : ''));

                $stage1 = "$tmp/$name.stage1.sch";
                $stage2 = "$tmp/$name.stage2.sch";
                $output = "$compiled/$name.xsl";

                $this->saxon($classpath, $sch, "$skeleton/iso_dsdl_include.xsl", $stage1);
                $this->saxon($classpath, $stage1, "$skeleton/iso_abstract_expand.xsl", $stage2);
                $this->saxon(
                    $classpath, $stage2, "$skeleton/iso_svrl_for_xslt2.xsl", $output,
                    $phase ? ['phase='.$phase] : [],
                );

                $this->line('  → '.str_replace(base_path().'/', '', $output).' ('.number_format((int) filesize($output)).' bytes)');
            }
        } finally {
            File::deleteDirectory($tmp);
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $params
     */
    private function saxon(string $classpath, string $source, string $xsl, string $out, array $params = []): void
    {
        $result = Process::run([
            'java', '-cp', $classpath, 'net.sf.saxon.Transform',
            '-s:'.$source, '-xsl:'.$xsl, '-o:'.$out,
            ...$params,
        ]);

        if (! $result->successful()) {
            $this->error('Saxon failed transforming '.basename($source).' via '.basename($xsl));
            $this->line($result->errorOutput() ?: $result->output());
            throw new \RuntimeException('Saxon transform failed');
        }
    }
}
