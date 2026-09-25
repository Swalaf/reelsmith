<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\User;
use App\Support\Installer;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;

/** Command-line alternative to the web installer, for servers you can SSH into and for local development. */
class InstallCommand extends Command
{
    protected $signature = 'reelsmith:install
        {--name=Admin : Admin full name}
        {--email= : Admin email}
        {--password= : Admin password}
        {--demo : Also seed sample users, projects and payments}
        {--force : Run even if already installed}';

    protected $description = 'Migrate, seed the catalog, create the admin account and lock the web installer';

    public function handle(): int
    {
        if (Installer::installed() && ! $this->option('force')) {
            $this->warn('Already installed. Use --force to re-run.');

            return self::SUCCESS;
        }

        $email = $this->option('email') ?: $this->ask('Admin email');
        $password = $this->option('password') ?: $this->secret('Admin password (min 8 characters)');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen((string) $password) < 8) {
            $this->error('A valid email and a password of at least 8 characters are required.');

            return self::FAILURE;
        }

        $this->call('migrate', ['--force' => true]);
        $this->call('db:seed', ['--class' => CatalogSeeder::class, '--force' => true]);
        if ($this->option('demo')) {
            $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
        }
        $this->callSilently('storage:link');

        User::updateOrCreate(['email' => $email], [
            'name' => $this->option('name'), 'password' => $password, 'role' => 'admin', 'status' => 'Active',
            'credits' => 100000, 'plan_id' => Plan::orderByDesc('price')->value('id'), 'email_verified_at' => now(),
        ]);
        Installer::markInstalled();

        $this->info('Installed. Log in at '.config('app.url')."/login as {$email}.");

        return self::SUCCESS;
    }
}
