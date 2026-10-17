<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Throwable;

class CheckFarmDeployment extends Command
{
    protected $signature = 'farm:check-deployment';

    protected $description = 'Check production configuration without changing settings or exposing secrets';

    public function handle(): int
    {
        $key = (string) config('app.key');
        $key = str_starts_with($key, 'base64:') ? (base64_decode(substr($key, 7), true) ?: '') : $key;
        $private = realpath(config('filesystems.disks.local.root'));
        $public = realpath(public_path());
        $checks = [
            'APP_ENV=production' => app()->environment('production'),
            'APP_DEBUG=false' => ! config('app.debug'),
            'Valid application encryption key' => Encrypter::supported($key, config('app.cipher')),
            'APP_URL uses HTTPS' => parse_url(config('app.url'), PHP_URL_SCHEME) === 'https',
            'Secure, HTTP-only session cookie' => config('session.secure') && config('session.http_only'),
            'Session encryption enabled' => (bool) config('session.encrypt'),
            'Session SameSite enabled' => in_array(config('session.same_site'), ['lax', 'strict'], true),
            'Private storage outside public directory' => $private && $public && ! str_starts_with(strtolower(str_replace('\\', '/', $private)).'/', strtolower(str_replace('\\', '/', $public)).'/'),
            'Private storage has no file-serving route' => ! config('filesystems.disks.local.serve'),
            'Storage and bootstrap cache writable' => is_writable(storage_path()) && is_writable(app()->bootstrapPath('cache')),
            'Production assets built; Vite hot file absent' => is_file(public_path('build/manifest.json')) && ! is_file(public_path('hot')),
        ];
        try {
            $connection = DB::connection();
            $checks['MySQL configured'] = $connection->getDriverName() === 'mysql';
            $checks['Dedicated database account with password'] = ! in_array($connection->getConfig('username'), ['', 'root'], true) && (string) $connection->getConfig('password') !== '';
            $migrator = app('migrator');
            $repository = $migrator->getRepository();
            $checks['Database migrations current'] = $repository->repositoryExists() && ! array_diff(array_keys($migrator->getMigrationFiles(database_path('migrations'))), $repository->getRan());
            $checks['Exactly one farm configured'] = DB::table('farms')->count() === 1;
            $checks['An active Owner role and account exist'] = DB::table('users as u')->join('roles as r', 'r.name', '=', 'u.role')->where('u.role', 'owner')->where('u.is_active', true)->where('r.is_active', true)->exists();
        } catch (Throwable $exception) {
            report($exception);
            $checks['Database readiness checks'] = false;
        }
        $this->table(['Check', 'Result'], collect($checks)->map(fn ($passed, $label) => [$label, $passed ? 'PASS' : 'FAIL'])->all());
        $this->line('Web server document root must be public/. Verify the live TLS certificate, trusted proxy configuration and off-site backup restore separately.');
        $this->line('Upgrade: retain .env/APP_KEY and private uploads; verify a backup restore; use maintenance mode, locked dependency installs, migrate --force, asset/config/route/view caches, then up.');

        return in_array(false, array_map(fn ($value) => (bool) $value, $checks), true) ? self::FAILURE : self::SUCCESS;
    }
}
