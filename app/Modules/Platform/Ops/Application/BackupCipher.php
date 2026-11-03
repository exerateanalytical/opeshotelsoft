<?php

namespace App\Modules\Platform\Ops\Application;

use RuntimeException;

/**
 * Streaming AES-256-CBC file encryption, byte-compatible with
 *   openssl enc -aes-256-cbc -salt -pbkdf2 -md sha256 -iter 100000 -pass pass:...
 * Output layout: "Salted__" | 8-byte salt | ciphertext (PKCS#7 padded).
 * So a backup can be decrypted on any box without PHP:
 *   openssl enc -d -aes-256-cbc -pbkdf2 -md sha256 -iter 100000 -pass env:BACKUP_KEY -in f.sql.gz.enc -out f.sql.gz
 */
class BackupCipher
{
    private const CHUNK = 1048576; // multiple of the 16-byte block size

    public function __construct(private string $passphrase, private int $iterations = 100000)
    {
        if ($passphrase === '') {
            throw new RuntimeException('Backup passphrase is empty (set BACKUP_KEY or APP_KEY).');
        }
    }

    public function encryptFile(string $in, string $out): void
    {
        $salt = random_bytes(8);
        [$key, $iv] = $this->derive($salt);
        $src = $this->open($in, 'rb');
        $dst = $this->open($out, 'wb');
        fwrite($dst, 'Salted__'.$salt);

        $buffer = '';
        while (! feof($src)) {
            $buffer .= (string) fread($src, self::CHUNK);
            // Keep at least one partial/complete block back for the padded final call.
            $full = intdiv(strlen($buffer), 16) * 16;
            if ($full > 16 && ! feof($src)) {
                $take = $full - 16;
                $ct = $this->raw('encrypt', substr($buffer, 0, $take), $key, $iv);
                $iv = substr($ct, -16);
                fwrite($dst, $ct);
                $buffer = substr($buffer, $take);
            }
        }
        $ct = openssl_encrypt($buffer, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($ct === false) {
            throw new RuntimeException('Encryption failed.');
        }
        fwrite($dst, $ct);
        fclose($src);
        fclose($dst);
    }

    public function decryptFile(string $in, string $out): void
    {
        $src = $this->open($in, 'rb');
        $head = (string) fread($src, 16);
        if (strlen($head) !== 16 || substr($head, 0, 8) !== 'Salted__') {
            throw new RuntimeException('Not an encrypted backup (missing Salted__ header).');
        }
        [$key, $iv] = $this->derive(substr($head, 8, 8));
        $dst = $this->open($out, 'wb');

        $buffer = '';
        while (! feof($src)) {
            $buffer .= (string) fread($src, self::CHUNK);
            $full = intdiv(strlen($buffer), 16) * 16;
            if ($full > 16 && ! feof($src)) {
                $take = $full - 16; // keep the last block for the padding check
                $chunk = substr($buffer, 0, $take);
                fwrite($dst, $this->raw('decrypt', $chunk, $key, $iv));
                $iv = substr($chunk, -16);
                $buffer = substr($buffer, $take);
            }
        }
        $pt = openssl_decrypt($buffer, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        fclose($src);
        if ($pt === false) {
            fclose($dst);
            @unlink($out);
            throw new RuntimeException('Decryption failed (wrong key or corrupted file).');
        }
        fwrite($dst, $pt);
        fclose($dst);
    }

    /** @return array{0: string, 1: string} */
    private function derive(string $salt): array
    {
        $kiv = hash_pbkdf2('sha256', $this->passphrase, $salt, $this->iterations, 48, true);

        return [substr($kiv, 0, 32), substr($kiv, 32, 16)];
    }

    private function raw(string $op, string $data, string $key, string $iv): string
    {
        $flags = OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING;
        $res = $op === 'encrypt'
            ? openssl_encrypt($data, 'aes-256-cbc', $key, $flags, $iv)
            : openssl_decrypt($data, 'aes-256-cbc', $key, $flags, $iv);
        if ($res === false) {
            throw new RuntimeException("Block $op failed.");
        }

        return $res;
    }

    /** @return resource */
    private function open(string $path, string $mode)
    {
        $h = @fopen($path, $mode);
        if ($h === false) {
            throw new RuntimeException("Cannot open $path");
        }

        return $h;
    }
}
