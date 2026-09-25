<?php

namespace Tests\Unit;

use App\Support\Installer;
use Tests\TestCase;

class InstallerEnvTest extends TestCase
{
    public function test_write_env_updates_and_quotes_values(): void
    {
        $path = base_path('.env');
        $backup = file_exists($path) ? file_get_contents($path) : null;
        try {
            file_put_contents($path, "APP_NAME=Old\n#DB_HOST=127.0.0.1\n");
            Installer::writeEnv(['APP_NAME' => 'My App', 'DB_HOST' => 'db', 'DB_PASSWORD' => 'p$ss"word']);
            $env = file_get_contents($path);
            $this->assertStringContainsString('APP_NAME="My App"', $env);
            $this->assertStringContainsString("DB_HOST=db\n", $env);
            $this->assertStringContainsString('DB_PASSWORD="p\\$ss\\"word"', $env);
        } finally {
            $backup === null ? @unlink($path) : file_put_contents($path, $backup);
        }
    }
}
