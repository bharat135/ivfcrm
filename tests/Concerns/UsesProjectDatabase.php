<?php

namespace Tests\Concerns;

/**
 * Points a feature test at the real MySQL schema instead of the in-memory
 * SQLite database phpunit.xml configures.
 *
 * The CRM's heavier features — server-side DataTables joins, generated
 * columns, the seeded roles and pipeline — only exist in the real schema, so
 * the tests that exercise them read the connection details straight out of
 * .env. Wrapped in DatabaseTransactions by every caller, so nothing is left
 * behind in the developer's database.
 */
trait UsesProjectDatabase
{
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        config(['database.default' => 'mysql'] + $this->projectConnection());
    }

    /** @return array<string,string> */
    private function projectConnection(): array
    {
        $env = [];

        foreach (file(base_path('.env'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $env[trim($key)] = trim($value, " \t\"'");
        }

        return [
            'database.connections.mysql.host'     => $env['DB_HOST'] ?? '127.0.0.1',
            'database.connections.mysql.port'     => $env['DB_PORT'] ?? '3306',
            'database.connections.mysql.database' => $env['DB_DATABASE'] ?? 'ivfcrm',
            'database.connections.mysql.username' => $env['DB_USERNAME'] ?? 'root',
            'database.connections.mysql.password' => $env['DB_PASSWORD'] ?? '',
        ];
    }
}
