<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Profile / registration / 2FA feature.
 * Algorithm: from-scratch RSA (chunked c = m^e mod n). HMAC-SHA256 for integrity.
 * TOTP secret is also RSA-encrypted (no Laravel Crypt, no AES).
 */
class ProfileSecurity
{
    /** @var array<int, string> */
    private array $nameCache = [];

    /** @var array<int, string> */
    private array $emailCache = [];

    public function __construct(
        private ProfileRsa $rsa,
        private MacService $mac,
        private KeyManager $keys,
    ) {
        $this->keys->ensureSystemKeys();
    }

    public static function emailHash(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }

    public function encryptField(string $plaintext, ?array $publicKey = null): string
    {
        return $this->rsa->encrypt($plaintext, $publicKey ?? $this->keys->rsaPublic());
    }

    public function decryptField(string $ciphertext): string
    {
        if ($ciphertext === '') {
            return '';
        }
        return $this->rsa->decrypt($ciphertext, $this->keys->resolveRsaPrivateFromCipher($ciphertext));
    }

    public function encryptProfile(array $fields): array
    {
        $pub = $this->keys->rsaPublic();
        $encrypted = [
            'name' => $this->encryptField($fields['name'], $pub),
            'email' => $this->encryptField($fields['email'], $pub),
            'phone' => $this->encryptField($fields['phone'], $pub),
            'address' => $this->encryptField($fields['address'] ?? '', $pub),
            'nid_no' => $this->encryptField($fields['nid_no'] ?? '', $pub),
        ];
        $encrypted['mac'] = $this->generateProfileMac($encrypted);
        $encrypted['email_hash'] = self::emailHash($fields['email']);

        return $encrypted;
    }

    public function decryptProfile(User $user): array
    {
        $this->assertIntegrity($user);

        $plain = [
            'name' => $this->decryptField($this->stored($user, 'name') ?? ''),
            'email' => $this->decryptField($this->stored($user, 'email') ?? ''),
            'phone' => $this->decryptOptional($user, 'phone'),
            'address' => $this->decryptOptional($user, 'address'),
            'nid_no' => $this->decryptOptional($user, 'nid_no'),
        ];
        $this->nameCache[(int) $user->id] = $plain['name'];
        $this->emailCache[(int) $user->id] = $plain['email'];

        return $plain;
    }

    public function generateProfileMac(array $encryptedFields): string
    {
        return $this->mac->generate($this->mac->join([
            $encryptedFields['name'] ?? '',
            $encryptedFields['email'] ?? '',
            $encryptedFields['phone'] ?? '',
            $encryptedFields['address'] ?? '',
            $encryptedFields['nid_no'] ?? '',
        ]));
    }

    public function verifyProfileMac(User $user): bool
    {
        $mac = $this->stored($user, 'mac');
        if (!$mac) {
            return false;
        }

        return $this->mac->verify($this->mac->join([
            $this->stored($user, 'name') ?? '',
            $this->stored($user, 'email') ?? '',
            $this->stored($user, 'phone') ?? '',
            $this->stored($user, 'address') ?? '',
            $this->stored($user, 'nid_no') ?? '',
        ]), $mac);
    }

    public function assertIntegrity(User $user): void
    {
        if (!$this->verifyProfileMac($user)) {
            throw new RuntimeException('Profile integrity check failed.');
        }
    }

    public function hydrateDecrypted(?User $user): ?User
    {
        if (!$user) {
            return null;
        }

        try {
            $plain = $this->decryptProfile($user);
            $user->setAttribute('name', $plain['name']);
            $user->setAttribute('email', $plain['email']);
            $user->setAttribute('phone', $plain['phone']);
            $user->setAttribute('address', $plain['address']);
            $user->setAttribute('nid_no', $plain['nid_no']);
            return $user;
        } catch (\Throwable) {
            $user->setAttribute('name', '[integrity failed]');
            $user->setAttribute('email', '—');
            return $user;
        }
    }

    public function hydrateName(?User $user): ?User
    {
        if (!$user) {
            return null;
        }

        $id = (int) $user->id;
        if (!isset($this->nameCache[$id])) {
            try {
                $this->assertIntegrity($user);
                $this->nameCache[$id] = $this->decryptField($this->stored($user, 'name') ?? '');
            } catch (\Throwable) {
                $this->nameCache[$id] = '[integrity failed]';
            }
        }

        $user->setAttribute('name', $this->nameCache[$id]);
        return $user;
    }

    public function hydrateNameEmail(?User $user): ?User
    {
        $user = $this->hydrateName($user);
        if (!$user) {
            return null;
        }

        $id = (int) $user->id;
        if (($this->nameCache[$id] ?? '') === '[integrity failed]') {
            $user->setAttribute('email', '—');
            return $user;
        }

        if (!isset($this->emailCache[$id])) {
            try {
                $this->emailCache[$id] = $this->decryptField($this->stored($user, 'email') ?? '');
            } catch (\Throwable) {
                $this->emailCache[$id] = '—';
            }
        }

        $user->setAttribute('email', $this->emailCache[$id]);
        return $user;
    }

    public function encryptTotpSecret(string $secret): string
    {
        return $this->encryptField($secret);
    }

    public function decryptTotpSecret(string $stored): string
    {
        if ($stored === '') {
            throw new RuntimeException('Missing TOTP secret.');
        }

        if (str_starts_with($stored, 'rsa_')) {
            return $this->decryptField($stored);
        }

        // Existing rows stored with Laravel Crypt before RSA wrapping.
        return Crypt::decryptString($stored);
    }

    private function decryptOptional(User $user, string $field): string
    {
        $cipher = $this->stored($user, $field);
        if ($cipher === null || $cipher === '') {
            return '';
        }
        return $this->decryptField($cipher);
    }

    private function stored(User $user, string $field): ?string
    {
        $originals = $user->getRawOriginal();
        if (array_key_exists($field, $originals)) {
            $value = $originals[$field];
            return $value === null ? null : (string) $value;
        }
        $value = $user->getAttribute($field);
        return $value === null ? null : (string) $value;
    }
}


namespace App\Services;

use RuntimeException;

/**
 * From-scratch RSA for the profile/auth feature (PII + TOTP secret).
 * Not used for items/posts/requests — those use ECC.
 */
class ProfileRsa
{
    private const VERSION = 'rsa_v1';
    private const E = '65537';

    private CryptoMath $math;

    public function __construct(?CryptoMath $math = null)
    {
        $this->math = $math ?? new CryptoMath();
    }

    /** @return array{version:string,bits:int,n:string,e:string,d:string,p:string,q:string} */
    public function generateKeyPair(int $bits = 1024): array
    {
        if ($bits < 512 || $bits % 2 !== 0) {
            throw new RuntimeException('RSA bits must be an even number >= 512.');
        }

        $half = intdiv($bits, 2);
        $e = self::E;

        do {
            $p = $this->generatePrime($half);
            $q = $this->generatePrime($half);
        } while (bccomp($p, $q) === 0);

        $n = bcmul($p, $q);
        $phi = bcmul(bcsub($p, '1'), bcsub($q, '1'));
        $d = $this->modInverse($e, $phi);

        if ($d === null) {
            return $this->generateKeyPair($bits);
        }

        return [
            'version' => self::VERSION,
            'bits' => $bits,
            'n' => $n,
            'e' => $e,
            'd' => $d,
            'p' => $p,
            'q' => $q,
        ];
    }

    public function encrypt(string $plaintext, array $publicKey): string
    {
        $n = $this->math->fromDec((string) $publicKey['n']);
        $e = $this->math->fromDec((string) $publicKey['e']);
        $maxBytes = intdiv($this->math->bitLength($n) + 7, 8) - 1;
        if ($maxBytes < 1) {
            throw new RuntimeException('RSA modulus too small.');
        }

        $chunks = [];
        $len = strlen($plaintext);
        for ($i = 0; $i < $len; $i += $maxBytes) {
            $block = substr($plaintext, $i, $maxBytes);
            $m = $this->math->fromBytes($block);
            if ($this->math->cmp($m, $n) >= 0) {
                throw new RuntimeException('RSA message block too large.');
            }
            $c = $this->math->powmod($m, $e, $n);
            $chunks[] = $this->math->toHex($c);
        }

        $version = $publicKey['version'] ?? self::VERSION;

        return $version . ':' . base64_encode(json_encode([
            'chunks' => $chunks,
        ], JSON_THROW_ON_ERROR));
    }

    public function decrypt(string $ciphertext, array $privateKey): string
    {
        $parts = explode(':', $ciphertext, 2);
        if (count($parts) !== 2) {
            throw new RuntimeException('Invalid RSA ciphertext format.');
        }

        $payload = json_decode(base64_decode($parts[1], true) ?: '', true);
        if (!is_array($payload) || !isset($payload['chunks']) || !is_array($payload['chunks'])) {
            throw new RuntimeException('Corrupt RSA ciphertext.');
        }

        $out = '';
        foreach ($payload['chunks'] as $hex) {
            $c = $this->math->fromHex((string) $hex);
            $m = $this->privateModPow($c, $privateKey);
            $out .= $this->math->toBytes($m);
        }

        return $out;
    }

    public function publicOnly(array $key): array
    {
        return [
            'version' => $key['version'] ?? self::VERSION,
            'bits' => $key['bits'] ?? null,
            'n' => $key['n'],
            'e' => $key['e'],
        ];
    }

    private function privateModPow(mixed $c, array $key): mixed
    {
        $n = $this->math->fromDec((string) $key['n']);
        $d = $this->math->fromDec((string) $key['d']);
        if (!isset($key['p'], $key['q'])) {
            return $this->math->powmod($c, $d, $n);
        }

        $p = $this->math->fromDec((string) $key['p']);
        $q = $this->math->fromDec((string) $key['q']);
        $dp = $this->math->mod($d, $this->math->sub($p, $this->math->fromDec('1')));
        $dq = $this->math->mod($d, $this->math->sub($q, $this->math->fromDec('1')));
        $qinv = $this->math->invert($q, $p);
        if ($qinv === null) {
            return $this->math->powmod($c, $d, $n);
        }

        $m1 = $this->math->powmod($this->math->mod($c, $p), $dp, $p);
        $m2 = $this->math->powmod($this->math->mod($c, $q), $dq, $q);
        $diff = $this->math->sub($m1, $m2);
        if ($this->math->cmp($diff, $this->math->fromDec('0')) < 0) {
            $diff = $this->math->add($diff, $p);
        }
        $h = $this->math->mod($this->math->mul($qinv, $diff), $p);

        return $this->math->add($m2, $this->math->mul($h, $q));
    }

    private function generatePrime(int $bits): string
    {
        for ($attempt = 0; $attempt < 20000; $attempt++) {
            $candidate = $this->randomOdd($bits);
            if (!$this->passesSmallPrimeSieve($candidate)) {
                continue;
            }
            if ($this->isProbablePrime($candidate, $bits >= 1024 ? 8 : 5)) {
                return $this->math->toDec($candidate);
            }
        }
        throw new RuntimeException('Unable to generate RSA prime.');
    }

    private function passesSmallPrimeSieve(mixed $n): bool
    {
        static $primes = [
            3, 5, 7, 11, 13, 17, 19, 23, 29, 31, 37, 41, 43, 47, 53, 59, 61, 67, 71, 73, 79, 83, 89, 97,
            101, 103, 107, 109, 113, 127, 131, 137, 139, 149, 151, 157, 163, 167, 173, 179, 181, 191, 193, 197, 199,
        ];
        foreach ($primes as $p) {
            $ps = $this->math->fromDec((string) $p);
            if ($this->math->cmp($n, $ps) === 0) {
                return true;
            }
            if ($this->math->cmp($this->math->mod($n, $ps), $this->math->fromDec('0')) === 0) {
                return false;
            }
        }
        return true;
    }

    private function randomOdd(int $bits): mixed
    {
        $bytes = intdiv($bits + 7, 8);
        $raw = random_bytes($bytes);
        $raw[0] = chr(ord($raw[0]) | 0x80);
        $raw[$bytes - 1] = chr(ord($raw[$bytes - 1]) | 0x01);
        return $this->math->fromBytes($raw);
    }

    private function isProbablePrime(mixed $n, int $rounds = 8): bool
    {
        $two = $this->math->fromDec('2');
        $three = $this->math->fromDec('3');
        if ($this->math->cmp($n, $two) < 0) {
            return false;
        }
        if ($this->math->cmp($n, $two) === 0 || $this->math->cmp($n, $three) === 0) {
            return true;
        }
        if ($this->math->cmp($this->math->mod($n, $two), $this->math->fromDec('0')) === 0) {
            return false;
        }

        $one = $this->math->fromDec('1');
        $n1 = $this->math->sub($n, $one);
        $s = 0;
        $d = $n1;
        while ($this->math->cmp($this->math->mod($d, $two), $this->math->fromDec('0')) === 0) {
            $d = $this->math->div($d, $two);
            $s++;
        }

        for ($i = 0; $i < $rounds; $i++) {
            $a = $this->randomRange($two, $this->math->sub($n, $two));
            $x = $this->math->powmod($a, $d, $n);
            if ($this->math->cmp($x, $one) === 0 || $this->math->cmp($x, $n1) === 0) {
                continue;
            }
            $cont = false;
            for ($r = 1; $r < $s; $r++) {
                $x = $this->math->powmod($x, $two, $n);
                if ($this->math->cmp($x, $n1) === 0) {
                    $cont = true;
                    break;
                }
                if ($this->math->cmp($x, $one) === 0) {
                    return false;
                }
            }
            if (!$cont) {
                return false;
            }
        }

        return true;
    }

    private function randomRange(mixed $min, mixed $max): mixed
    {
        $range = $this->math->sub($max, $min);
        $bytes = intdiv($this->math->bitLength($range) + 7, 8);
        $span = $this->math->add($range, $this->math->fromDec('1'));
        do {
            $r = $this->math->mod($this->math->fromBytes(random_bytes(max(1, $bytes))), $span);
        } while ($this->math->cmp($r, $range) > 0);

        return $this->math->add($min, $r);
    }

    private function modInverse(string $a, string $m): ?string
    {
        $inv = $this->math->invert($this->math->fromDec($a), $this->math->fromDec($m));
        return $inv === null ? null : $this->math->toDec($inv);
    }
}
