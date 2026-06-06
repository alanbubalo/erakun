<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Support\Sleep;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

final class CisProcess
{
    public const int PORT = 8766;

    public const string BASE_URL = 'http://127.0.0.1:'.self::PORT;

    private static ?Process $process = null;

    private static bool $ready = false;

    private static string $bootLog = '';

    public static function siblingPath(): ?string
    {
        $path = realpath(__DIR__.'/../../../erakun-porezna');

        return ($path !== false && is_file($path.'/artisan')) ? $path : null;
    }

    public static function boot(): bool
    {
        if (self::$ready) {
            return true;
        }

        $cwd = self::siblingPath();
        if ($cwd === null) {
            return false;
        }

        $php = (new PhpExecutableFinder)->find();
        if ($php === false) {
            return false;
        }

        // PHPUnit's <env> entries (APP_ENV=testing, DB_DATABASE=:memory:, …) leak
        // into the subprocess via $_SERVER and would force porezna onto an empty
        // in-memory database. Setting them to false unsets them in the child so it
        // boots from its own .env.
        $cleared = [
            'APP_ENV' => false,
            'APP_MAINTENANCE_DRIVER' => false,
            'BCRYPT_ROUNDS' => false,
            'BROADCAST_CONNECTION' => false,
            'CACHE_STORE' => false,
            'DB_CONNECTION' => false,
            'DB_DATABASE' => false,
            'DB_URL' => false,
            'MAIL_MAILER' => false,
            'QUEUE_CONNECTION' => false,
            'SESSION_DRIVER' => false,
            'PULSE_ENABLED' => false,
            'TELESCOPE_ENABLED' => false,
            'NIGHTWATCH_ENABLED' => false,
        ];

        self::$process = new Process(
            [$php, 'artisan', 'serve', '--host=127.0.0.1', '--port='.self::PORT, '--no-reload'],
            $cwd,
            env: $cleared + ['PHP_CLI_SERVER_WORKERS' => '1'],
        );
        self::$process->start();

        register_shutdown_function(self::stop(...));

        self::$ready = self::waitForBoot();

        if (! self::$ready && self::$process instanceof Process) {
            self::$bootLog = trim(self::$process->getOutput()."\n".self::$process->getErrorOutput());
        }

        return self::$ready;
    }

    public static function isReady(): bool
    {
        return self::$ready;
    }

    public static function bootLog(): string
    {
        return self::$bootLog;
    }

    public static function reset(): void
    {
        self::httpRequest('POST', self::BASE_URL.'/admin/reset', timeout: 2);
    }

    /**
     * Register a participant in the AMS: OIB → the MPS that publishes them.
     * Stands in for the onboarding-time registration so the directory is
     * populated deterministically after each reset.
     */
    public static function registerParticipant(string $oib, string $mpsUrl): void
    {
        self::httpRequest(
            'PUT',
            self::BASE_URL.'/ams/participants/'.$oib,
            timeout: 2,
            payload: (string) json_encode(['mps_url' => $mpsUrl]),
        );
    }

    public static function stop(): void
    {
        if (self::$process instanceof Process && self::$process->isRunning()) {
            $pid = self::$process->getPid();
            self::$process->stop(2);

            if ($pid !== null) {
                // belt-and-suspenders: the inner `php -S` worker may outlive its
                // artisan-serve supervisor parent; SIGKILL anything still on our port.
                @exec(sprintf('lsof -ti :%d 2>/dev/null | xargs kill -9 2>/dev/null', self::PORT));
            }
        }

        self::$process = null;
        self::$ready = false;
    }

    private static function waitForBoot(): bool
    {
        $deadline = microtime(true) + 8.0;

        while (microtime(true) < $deadline) {
            [$status] = self::httpRequest('GET', self::BASE_URL.'/up', timeout: 1);

            if ($status >= 200 && $status < 300) {
                return true;
            }

            Sleep::usleep(150_000);
        }

        return false;
    }

    /**
     * @return array{0:int, 1:string} [http_status, body]
     */
    private static function httpRequest(string $method, string $url, int $timeout, ?string $payload = null): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'Content-Type: application/json',
            ]);
        }

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return [$status, is_string($body) ? $body : ''];
    }
}
