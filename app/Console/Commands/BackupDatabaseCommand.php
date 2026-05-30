<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BackupDatabaseCommand extends Command
{
    protected $signature   = 'emc:backup
                                {--db : Back up the database}
                                {--storage : Back up the storage/app directory}
                                {--all : Back up both database and storage (default when no flag given)}
                                {--disk=local : Storage disk to write backups to}
                                {--dir=backups : Directory inside the disk to write backups to}';

    protected $description = 'Create a database dump and/or storage archive for disaster recovery.';

    public function handle(): int
    {
        $doDb      = $this->option('db')      || $this->option('all') || (! $this->option('storage'));
        $doStorage = $this->option('storage') || $this->option('all');
        $disk      = $this->option('disk');
        $dir       = rtrim($this->option('dir'), '/');
        $stamp     = now()->format('Y-m-d_H-i-s');

        $ok = true;

        if ($doDb) {
            $ok = $this->backupDatabase($disk, $dir, $stamp) && $ok;
        }

        if ($doStorage) {
            $ok = $this->backupStorage($disk, $dir, $stamp) && $ok;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function backupDatabase(string $disk, string $dir, string $stamp): bool
    {
        $host     = config('database.connections.mysql.host', '127.0.0.1');
        $port     = config('database.connections.mysql.port', 3306);
        $name     = config('database.connections.mysql.database');
        $user     = config('database.connections.mysql.username');
        $pass     = config('database.connections.mysql.password');
        $filename = "{$dir}/{$stamp}_db_{$name}.sql";

        if (! $name) {
            $this->error('Database name not configured. Check DB_DATABASE in .env');
            return false;
        }

        $passArg  = $pass ? "-p" . escapeshellarg($pass) : '';
        $dumpPath = Storage::disk($disk)->path($filename);

        $this->ensureDir(dirname($dumpPath));

        $cmd      = sprintf(
            'mysqldump --single-transaction --no-tablespaces -h %s -P %s -u %s %s %s > %s 2>&1',
            escapeshellarg($host),
            (int) $port,
            escapeshellarg($user),
            $passArg,
            escapeshellarg($name),
            escapeshellarg($dumpPath)
        );

        $this->info("Dumping database → {$filename}");
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->error('mysqldump failed: ' . implode("\n", $output));
            return false;
        }

        $sizeMb = round(filesize($dumpPath) / 1024 / 1024, 2);
        $this->info("  Database backup written ({$sizeMb} MB)");

        return true;
    }

    private function backupStorage(string $disk, string $dir, string $stamp): bool
    {
        $sourcePath = storage_path('app');
        $filename   = "{$dir}/{$stamp}_storage.tar.gz";
        $dumpPath   = Storage::disk($disk)->path($filename);

        $this->ensureDir(dirname($dumpPath));

        $this->info("Archiving storage/app → {$filename}");

        $cmd = sprintf(
            'tar -czf %s -C %s . 2>&1',
            escapeshellarg($dumpPath),
            escapeshellarg($sourcePath)
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->error('tar failed: ' . implode("\n", $output));
            return false;
        }

        $sizeMb = round(filesize($dumpPath) / 1024 / 1024, 2);
        $this->info("  Storage backup written ({$sizeMb} MB)");

        return true;
    }

    private function ensureDir(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
}
