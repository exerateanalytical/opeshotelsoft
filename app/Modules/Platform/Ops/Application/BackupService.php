<?php

namespace App\Modules\Platform\Ops\Application;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Encrypted PostgreSQL backups: pg_dump (plain SQL) -> gzip -> AES-256 (openssl-compatible),
 * local retention, optional copy to a filesystem disk (S3-compatible), and a restore drill
 * into a throw-away database.
 */
class BackupService
{
    public const SUFFIX = '.sql.gz.enc';

    public function dir(): string
    {
        $dir = rtrim((string) config('ops.backup.path'), '/\\');
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create backup directory $dir");
        }

        return $dir;
    }

    public function cipher(): BackupCipher
    {
        return new BackupCipher((string) (config('ops.backup.key') ?: config('app.key')), (int) config('ops.backup.pbkdf2_iterations', 100000));
    }

    /** @return array{file: string, bytes: int, pruned: int, copied: bool} */
    public function backup(?string $connection = null): array
    {
        $db = $this->connection($connection);
        $dir = $this->dir();
        $base = $dir.DIRECTORY_SEPARATOR.$db['database'].'-'.now()->utc()->format('Ymd-His');
        $sql = $base.'.sql';
        $gz = $sql.'.gz';
        $final = $base.self::SUFFIX;

        try {
            $this->run([$this->bin('pg_dump'), '--no-owner', '--no-privileges', '--format=plain',
                '--file='.$sql, ...$this->connArgs($db), $db['database']], $db);
            $this->gzip($sql, $gz);
            @unlink($sql);
            $this->cipher()->encryptFile($gz, $final);
            @unlink($gz);
            @chmod($final, 0600);
        } finally {
            @unlink($sql);
            @unlink($gz);
        }

        $copied = $this->copyToDisk($final);
        $pruned = $this->prune();
        $bytes = (int) filesize($final);
        Log::info('ops.backup.completed', ['file' => basename($final), 'bytes' => $bytes, 'copied' => $copied, 'pruned' => $pruned]);

        return ['file' => $final, 'bytes' => $bytes, 'pruned' => $pruned, 'copied' => $copied];
    }

    /**
     * Restore a backup into a scratch database, sanity-check it, then drop it.
     *
     * @return array{file: string, database: string, tables: int, migrations: int}
     */
    public function verify(?string $file = null, ?string $connection = null, bool $keep = false): array
    {
        $db = $this->connection($connection);
        $file ??= $this->latest() ?? throw new RuntimeException('No backup found in '.$this->dir());
        $scratch = substr(preg_replace('/[^a-z0-9_]/', '_', strtolower($db['database'])), 0, 40).'_restore_check';
        $gz = $this->tmp('gz');
        $sql = $this->tmp('sql');

        try {
            $this->cipher()->decryptFile($file, $gz);
            $this->gunzip($gz, $sql);
            @unlink($gz);

            $this->psql($db, 'postgres', ['-c', "DROP DATABASE IF EXISTS \"$scratch\""]);
            $this->psql($db, 'postgres', ['-c', "CREATE DATABASE \"$scratch\""]);
            $this->psql($db, $scratch, ['-v', 'ON_ERROR_STOP=1', '-q', '-f', $sql]);

            $tables = (int) trim($this->psql($db, $scratch, ['-tAc',
                "select count(*) from information_schema.tables where table_schema='public'"]));
            $migrations = (int) trim($this->psql($db, $scratch, ['-tAc', 'select count(*) from migrations']));
            if ($tables === 0 || $migrations === 0) {
                throw new RuntimeException("Restore drill failed: $tables tables, $migrations migrations.");
            }
        } finally {
            @unlink($gz);
            @unlink($sql);
            if (! $keep) {
                try {
                    $this->psql($db, 'postgres', ['-c', "DROP DATABASE IF EXISTS \"$scratch\""]);
                } catch (\Throwable) {
                }
            }
        }
        Log::info('ops.backup.verified', ['file' => basename($file), 'tables' => $tables, 'migrations' => $migrations]);

        return ['file' => $file, 'database' => $scratch, 'tables' => $tables, 'migrations' => $migrations];
    }

    public function latest(): ?string
    {
        $files = glob($this->dir().DIRECTORY_SEPARATOR.'*'.self::SUFFIX) ?: [];
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a) ?: strcmp($b, $a));

        return $files[0] ?? null;
    }

    /** Delete local (and remote-disk) backups older than the retention window. */
    public function prune(): int
    {
        $days = (int) config('ops.backup.retention_days', 14);
        if ($days <= 0) {
            return 0;
        }
        $cutoff = now()->subDays($days)->getTimestamp();
        $n = 0;
        foreach (glob($this->dir().DIRECTORY_SEPARATOR.'*'.self::SUFFIX) ?: [] as $f) {
            if (filemtime($f) < $cutoff && @unlink($f)) {
                $n++;
            }
        }
        if ($disk = config('ops.backup.disk')) {
            $fs = Storage::disk($disk);
            foreach ($fs->files((string) config('ops.backup.disk_prefix')) as $f) {
                if (str_ends_with($f, self::SUFFIX) && $fs->lastModified($f) < $cutoff) {
                    $fs->delete($f);
                }
            }
        }

        return $n;
    }

    private function copyToDisk(string $file): bool
    {
        $disk = config('ops.backup.disk');
        if (! $disk) {
            return false;
        }
        $h = fopen($file, 'rb');
        try {
            return (bool) Storage::disk($disk)->writeStream(trim((string) config('ops.backup.disk_prefix'), '/').'/'.basename($file), $h);
        } finally {
            if (is_resource($h)) {
                fclose($h);
            }
        }
    }

    /** @return array{host: string, port: string, database: string, username: string, password: string} */
    private function connection(?string $name): array
    {
        $name ??= config('database.default');
        $c = config("database.connections.$name");
        if (($c['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException("Connection $name is not PostgreSQL.");
        }

        return ['host' => (string) ($c['host'] ?? '127.0.0.1'), 'port' => (string) ($c['port'] ?? '5432'),
            'database' => (string) $c['database'], 'username' => (string) ($c['username'] ?? 'postgres'),
            'password' => (string) ($c['password'] ?? '')];
    }

    private function connArgs(array $db): array
    {
        return ['--host='.$db['host'], '--port='.$db['port'], '--username='.$db['username'], '--no-password'];
    }

    private function psql(array $db, string $database, array $args): string
    {
        return $this->run([$this->bin('psql'), ...$this->connArgs($db), '--dbname='.$database, ...$args], $db);
    }

    /** Runs a client tool; the password goes in PGPASSWORD, never on the command line. */
    private function run(array $cmd, array $db): string
    {
        $r = Process::timeout(3600)->env(['PGPASSWORD' => $db['password']])->run($cmd);
        if (! $r->successful()) {
            throw new RuntimeException(basename($cmd[0]).' failed: '.trim($r->errorOutput() ?: $r->output()));
        }

        return $r->output();
    }

    private function bin(string $tool): string
    {
        $dir = (string) config('ops.backup.pg_bin');
        if ($dir === '') {
            return $tool;
        }
        $path = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$tool;

        return PHP_OS_FAMILY === 'Windows' && is_file($path.'.exe') ? $path.'.exe' : $path;
    }

    private function gzip(string $in, string $out): void
    {
        $src = fopen($in, 'rb');
        $dst = gzopen($out, 'wb6');
        while (! feof($src)) {
            gzwrite($dst, (string) fread($src, 1048576));
        }
        fclose($src);
        gzclose($dst);
    }

    private function gunzip(string $in, string $out): void
    {
        $src = gzopen($in, 'rb');
        $dst = fopen($out, 'wb');
        while (! gzeof($src)) {
            fwrite($dst, (string) gzread($src, 1048576));
        }
        gzclose($src);
        fclose($dst);
    }

    private function tmp(string $ext): string
    {
        return $this->dir().DIRECTORY_SEPARATOR.'.restore-'.bin2hex(random_bytes(6)).'.'.$ext;
    }
}
