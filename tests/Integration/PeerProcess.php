<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Support\Sleep;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Boots a second copy of *this* application as the "receiver" access point
 * (the buyer's intermediary) for the Phase 5d two-instance round trip.
 *
 * It runs `php artisan serve --env=peer` on its own port against a dedicated
 * SQLite database (database/peer.sqlite), so an invoice can travel the full
 * 4-corner path over real HTTP: instance A (the test process) → this peer.
 *
 * Mirrors {@see CisProcess}; the extra wrinkle is that the peer database is
 * migrated fresh before the server starts.
 */
final class PeerProcess
{
    public const int PORT = 8002;

    public const string BASE_URL = 'http://127.0.0.1:'.self::PORT;

    private static ?Process $process = null;

    private static bool $ready = false;

    private static string $bootLog = '';

    /**
     * PHPUnit's <env> entries (APP_ENV=testing, DB_DATABASE=:memory:, …) leak
     * into a subprocess via $_SERVER and would override `.env.peer`, forcing the
     * peer onto the test's in-memory database. Unsetting them lets it boot purely
     * from .env.peer.
     *
     * @var array<string, false>
     */
    private const array CLEARED_ENV = [
        'APP_ENV' => false,
        'APP_KEY' => false,
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

    /**
     * Project root, resolved relative to this file rather than via base_path():
     * boot() runs in Pest's beforeAll, before the test application is bootstrapped.
     */
    private static function rootPath(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function boot(): bool
    {
        if (self::$ready) {
            return true;
        }

        $php = (new PhpExecutableFinder)->find();
        if ($php === false) {
            return false;
        }

        if (! self::migrateFresh($php)) {
            return false;
        }

        self::$process = new Process(
            [$php, 'artisan', 'serve', '--host=127.0.0.1', '--port='.self::PORT, '--env=peer', '--no-reload'],
            self::rootPath(),
            env: self::CLEARED_ENV + ['PHP_CLI_SERVER_WORKERS' => '1'],
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

    public static function stop(): void
    {
        if (self::$process instanceof Process && self::$process->isRunning()) {
            self::$process->stop(2);

            // belt-and-suspenders: the inner `php -S` worker may outlive its
            // artisan-serve supervisor parent; SIGKILL anything still on our port.
            @exec(sprintf('lsof -ti :%d 2>/dev/null | xargs kill -9 2>/dev/null', self::PORT));
        }

        self::$process = null;
        self::$ready = false;
    }

    /**
     * Rebuild the peer's schema before the server opens its connection.
     */
    private static function migrateFresh(string $php): bool
    {
        $migrate = new Process(
            [$php, 'artisan', 'migrate:fresh', '--env=peer', '--force', '--no-interaction'],
            self::rootPath(),
            env: self::CLEARED_ENV,
        );
        $migrate->run();

        if (! $migrate->isSuccessful()) {
            self::$bootLog = trim($migrate->getOutput()."\n".$migrate->getErrorOutput());

            return false;
        }

        return true;
    }

    private static function waitForBoot(): bool
    {
        $deadline = microtime(true) + 8.0;

        while (microtime(true) < $deadline) {
            [$status] = self::request('GET', '/up', timeout: 1);

            if ($status >= 200 && $status < 300) {
                return true;
            }

            Sleep::usleep(150_000);
        }

        return false;
    }

    /**
     * Make a JSON API request to the peer and return the decoded response.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array{0:int, 1:array<string, mixed>} [http_status, decoded_json]
     */
    public static function api(string $method, string $path, ?array $payload = null): array
    {
        [$status, $body] = self::request(
            $method,
            $path,
            timeout: 5,
            payload: $payload === null ? null : (string) json_encode($payload),
        );

        $decoded = $body === '' ? [] : json_decode($body, true);

        return [$status, is_array($decoded) ? $decoded : []];
    }

    /**
     * @return array{0:int, 1:string} [http_status, body]
     */
    private static function request(string $method, string $path, int $timeout, ?string $payload = null): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::BASE_URL.$path,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return [$status, is_string($body) ? $body : ''];
    }
}
