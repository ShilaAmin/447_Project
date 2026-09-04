<?php

namespace App\Services;

use RuntimeException;

/**
 * Key lifecycle: generate, distribute (public accessors), store, rotate.
 * Private key material is encrypted at rest with scratch RSA (asymmetric wrap, no AES).
 */
class KeyManager
{
    /** @var array<string, array> */
    private array $publicCache = [];

    /** @var array<string, array> */
    private array $privateCache = [];

    private ?array $masterCache = null;

    public function __construct(
        private ProfileRsa $rsa,
        private ItemEcc $ecc,
    ) {
    }

    public function ensureSystemKeys(): void
    {
        $dir = $this->dir();
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        if (!$this->hasMaster()) {
            $this->writeMaster($this->rsa->generateKeyPair(1024));
        }

        if ($this->latestVersion('rsa') === 0) {
            $this->rotateRsa();
        }
        if ($this->latestVersion('ecc') === 0) {
            $this->rotateEcc();
        }
    }

    public function rotateRsa(): int
    {
        $this->ensureMasterOnly();
        $version = $this->latestVersion('rsa') + 1;
        $pair = $this->rsa->generateKeyPair(1024);
        $pair['version'] = 'rsa_v' . $version;
        $this->storeKeyed('rsa', $version, $pair);
        return $version;
    }

    public function rotateEcc(): int
    {
        $this->ensureMasterOnly();
        $version = $this->latestVersion('ecc') + 1;
        $pair = $this->ecc->generateKeyPair();
        $pair['version'] = 'ecc_v' . $version;
        $this->storeKeyed('ecc', $version, $pair);
        return $version;
    }

    public function rsaPublic(?int $version = null): array
    {
        $this->ensureSystemKeys();
        $v = $version ?? $this->latestVersion('rsa');
        return $this->rsa->publicOnly($this->loadPublic('rsa', $v));
    }

    public function rsaPrivate(?int $version = null): array
    {
        $this->ensureSystemKeys();
        $v = $version ?? $this->latestVersion('rsa');
        return $this->loadPrivate('rsa', $v);
    }

    public function eccPublic(?int $version = null): array
    {
        $this->ensureSystemKeys();
        $v = $version ?? $this->latestVersion('ecc');
        return $this->ecc->publicOnly($this->loadPublic('ecc', $v));
    }

    public function eccPrivate(?int $version = null): array
    {
        $this->ensureSystemKeys();
        $v = $version ?? $this->latestVersion('ecc');
        return $this->loadPrivate('ecc', $v);
    }

    public function resolveRsaPrivateFromCipher(string $ciphertext): array
    {
        $version = $this->parseVersion($ciphertext, 'rsa');
        return $this->rsaPrivate($version);
    }

    public function resolveEccPrivateFromCipher(string $ciphertext): array
    {
        $version = $this->parseVersion($ciphertext, 'ecc');
        return $this->eccPrivate($version);
    }

    public function generateUserKeys(int $userId): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        $this->ensureSystemKeys();
        $dir = $this->dir() . '/users/' . $userId;
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        // 512-bit is enough for per-user key material at registration (web request).
        // System payload keys remain 1024-bit and are generated offline.
        $rsa = $this->rsa->generateKeyPair(512);
        $rsa['version'] = 'rsa_user';
        $ecc = $this->ecc->generateKeyPair();
        $ecc['version'] = 'ecc_user';

        $this->writeProtected($dir . '/rsa.json', $rsa);
        $this->writeProtected($dir . '/ecc.json', $ecc);

        return [
            'rsa_public_key' => json_encode($this->rsa->publicOnly($rsa), JSON_THROW_ON_ERROR),
            'ecc_public_key' => json_encode($this->ecc->publicOnly($ecc), JSON_THROW_ON_ERROR),
        ];
    }

    private function storeKeyed(string $type, int $version, array $pair): void
    {
        $path = $this->dir() . '/' . $type . '_key_v' . $version . '.json';
        $this->writeProtected($path, $pair);
        file_put_contents($this->dir() . '/' . $type . '_latest.txt', (string) $version);
    }

    private function loadPublic(string $type, int $version): array
    {
        $cacheKey = $type . '_pub_' . $version;
        if (isset($this->publicCache[$cacheKey])) {
            return $this->publicCache[$cacheKey];
        }

        $path = $this->dir() . '/' . $type . '_key_v' . $version . '.json';
        if (!is_file($path)) {
            throw new RuntimeException("Missing {$type} key version {$version}.");
        }

        $key = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($key)) {
            throw new RuntimeException('Corrupt key file.');
        }
        unset($key['private_encrypted'], $key['d'], $key['p'], $key['q']);

        $this->publicCache[$cacheKey] = $key;

        return $key;
    }

    private function loadPrivate(string $type, int $version): array
    {
        $cacheKey = $type . '_prv_' . $version;
        if (isset($this->privateCache[$cacheKey])) {
            return $this->privateCache[$cacheKey];
        }

        $path = $this->dir() . '/' . $type . '_key_v' . $version . '.json';
        if (!is_file($path)) {
            throw new RuntimeException("Missing {$type} key version {$version}.");
        }

        $key = $this->readProtected($path);
        $this->privateCache[$cacheKey] = $key;

        return $key;
    }

    private function writeProtected(string $path, array $key): void
    {
        $masterPub = $this->rsa->publicOnly($this->master());
        $privateFields = [];
        foreach (['d', 'p', 'q'] as $field) {
            if (isset($key[$field])) {
                $privateFields[$field] = $this->rsa->encrypt((string) $key[$field], $masterPub);
                unset($key[$field]);
            }
        }
        $key['private_encrypted'] = $privateFields;
        file_put_contents($path, json_encode($key, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        chmod($path, 0600);
    }

    private function readProtected(string $path): array
    {
        $key = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($key)) {
            throw new RuntimeException('Corrupt key file.');
        }

        $master = $this->master();
        $enc = $key['private_encrypted'] ?? [];
        foreach (['d', 'p', 'q'] as $field) {
            if (isset($enc[$field])) {
                $key[$field] = $this->rsa->decrypt((string) $enc[$field], $master);
            }
        }
        unset($key['private_encrypted']);

        return $key;
    }

    private function writeMaster(array $pair): void
    {
        // Master private stays local file with restricted perms (bootstrap key).
        // Public part used to wrap other private keys asymmetrically.
        $path = $this->dir() . '/master_rsa.json';
        file_put_contents($path, json_encode($pair, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        chmod($path, 0600);
    }

    private function master(): array
    {
        if ($this->masterCache !== null) {
            return $this->masterCache;
        }

        $path = $this->dir() . '/master_rsa.json';
        if (!is_file($path)) {
            throw new RuntimeException('Master RSA key missing.');
        }
        $key = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($key) || !isset($key['n'], $key['d'], $key['e'])) {
            throw new RuntimeException('Corrupt master RSA key.');
        }
        $this->masterCache = $key;

        return $key;
    }

    private function hasMaster(): bool
    {
        return is_file($this->dir() . '/master_rsa.json');
    }

    private function ensureMasterOnly(): void
    {
        $dir = $this->dir();
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        if (!$this->hasMaster()) {
            $this->writeMaster($this->rsa->generateKeyPair(1024));
        }
    }

    private function latestVersion(string $type): int
    {
        $file = $this->dir() . '/' . $type . '_latest.txt';
        if (!is_file($file)) {
            return 0;
        }
        return (int) trim((string) file_get_contents($file));
    }

    private function parseVersion(string $ciphertext, string $type): int
    {
        $prefix = explode(':', $ciphertext, 2)[0] ?? '';
        if (preg_match('/^' . preg_quote($type, '/') . '_v(\d+)$/', $prefix, $m)) {
            return (int) $m[1];
        }
        // default latest
        $latest = $this->latestVersion($type);
        return $latest > 0 ? $latest : 1;
    }

    private function dir(): string
    {
        return storage_path('app/keys');
    }
}
