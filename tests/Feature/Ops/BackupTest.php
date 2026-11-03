<?php

namespace Tests\Feature\Ops;

use App\Modules\Platform\Ops\Application\BackupCipher;
use App\Modules\Platform\Ops\Application\BackupService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/backups-'.uniqid());
        config(['ops.backup.path' => $this->dir, 'ops.backup.key' => 'test-backup-passphrase', 'ops.backup.disk' => null]);
        $laragon = 'C:/laragon/bin/postgresql/postgresql-16/bin';
        if (! config('ops.backup.pg_bin') && is_dir($laragon)) {
            config(['ops.backup.pg_bin' => $laragon]);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function requirePgTools(): void
    {
        $bin = config('ops.backup.pg_bin');
        $r = Process::run([($bin ? rtrim($bin, '/').'/' : '').'pg_dump', '--version']);
        if (! $r->successful()) {
            $this->markTestSkipped('pg_dump not available');
        }
    }

    public function test_cipher_round_trip_across_chunk_boundaries(): void
    {
        File::ensureDirectoryExists($this->dir);
        $c = new BackupCipher('k', 1000);
        foreach ([1, 15, 16, 17, 1048576, 1048576 + 16, 2 * 1048576 + 5] as $size) {
            $plain = random_bytes($size);
            file_put_contents("$this->dir/p", $plain);
            $c->encryptFile("$this->dir/p", "$this->dir/e");
            $c->decryptFile("$this->dir/e", "$this->dir/d");
            $this->assertSame($plain, file_get_contents("$this->dir/d"), "size $size");
            $this->assertSame('Salted__', substr(file_get_contents("$this->dir/e"), 0, 8));
        }
    }

    public function test_wrong_key_is_rejected(): void
    {
        File::ensureDirectoryExists($this->dir);
        file_put_contents("$this->dir/p", str_repeat('hotel', 1000));
        (new BackupCipher('right', 1000))->encryptFile("$this->dir/p", "$this->dir/e");
        $this->expectException(\RuntimeException::class);
        (new BackupCipher('wrong', 1000))->decryptFile("$this->dir/e", "$this->dir/d");
    }

    public function test_output_decrypts_with_openssl_cli(): void
    {
        if (! Process::run(['openssl', 'version'])->successful()) {
            $this->markTestSkipped('openssl CLI not available');
        }
        File::ensureDirectoryExists($this->dir);
        $plain = random_bytes(70000);
        file_put_contents("$this->dir/p", $plain);
        (new BackupCipher('cli-pass'))->encryptFile("$this->dir/p", "$this->dir/e");

        $r = Process::run(['openssl', 'enc', '-d', '-aes-256-cbc', '-pbkdf2', '-md', 'sha256', '-iter', '100000',
            '-pass', 'pass:cli-pass', '-in', "$this->dir/e", '-out', "$this->dir/d"]);
        $this->assertTrue($r->successful(), $r->errorOutput());
        $this->assertSame($plain, file_get_contents("$this->dir/d"));
    }

    /**
     * Uses a separate source database: pg_dump on the test database would wait on the
     * locks held by the RefreshDatabase transaction.
     */
    public function test_backup_command_then_restore_drill(): void
    {
        $this->requirePgTools();
        $src = config('database.connections.pgsql.database').'_bk_src';
        config(['database.connections.ops_src' => array_merge(config('database.connections.pgsql'), ['database' => $src])]);
        $svc = app(BackupService::class);
        $psql = fn (string $db, string $sql) => (fn () => $this->psql($this->connection('ops_src'), $db, ['-v', 'ON_ERROR_STOP=1', '-c', $sql]))->call($svc);
        $psql('postgres', "DROP DATABASE IF EXISTS \"$src\"");
        $psql('postgres', "CREATE DATABASE \"$src\"");
        $psql($src, "CREATE TABLE migrations (id serial primary key, migration text); INSERT INTO migrations (migration) VALUES ('m1'); CREATE TABLE folios (id int, note text); INSERT INTO folios VALUES (1, 'CREATE TABLE secret-ish')");

        Storage::fake('backups');
        config(['ops.backup.disk' => 'backups']);
        try {
            $this->artisan('ops:backup', ['--connection' => 'ops_src'])->expectsOutputToContain('Backup written')->assertSuccessful();
            $file = $svc->latest();
            $this->assertNotNull($file);
            $this->assertStringEndsWith('.sql.gz.enc', $file);
            $this->assertSame('Salted__', substr(file_get_contents($file), 0, 8), 'backup must be encrypted');
            Storage::disk('backups')->assertExists('db-backups/'.basename($file));

            $this->artisan('ops:backup:verify', ['--connection' => 'ops_src'])
                ->expectsOutputToContain('(2 tables, 1 migrations)')->assertSuccessful();
        } finally {
            $psql('postgres', "DROP DATABASE IF EXISTS \"$src\"");
        }
    }

    public function test_retention_prunes_old_backups(): void
    {
        File::ensureDirectoryExists($this->dir);
        config(['ops.backup.retention_days' => 7]);
        touch("$this->dir/old.sql.gz.enc", now()->subDays(8)->getTimestamp());
        touch("$this->dir/new.sql.gz.enc", now()->subDays(1)->getTimestamp());
        touch("$this->dir/keep.txt", now()->subDays(30)->getTimestamp());

        $this->assertSame(1, app(BackupService::class)->prune());
        $this->assertFileDoesNotExist("$this->dir/old.sql.gz.enc");
        $this->assertFileExists("$this->dir/new.sql.gz.enc");
        $this->assertFileExists("$this->dir/keep.txt");
    }

    public function test_verify_fails_cleanly_without_backups(): void
    {
        File::ensureDirectoryExists($this->dir);
        $this->expectException(\RuntimeException::class);
        app(BackupService::class)->verify();
    }

    public function test_backups_are_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('ops:backup')->assertSuccessful();
    }
}
