<?php

namespace App\Services;

use RuntimeException;

/**
 * Trade / exchange-request feature.
 * Algorithm: from-scratch ECC NIST P-256 (ECIES in this file as ExchangeEcc). HMAC for integrity.
 */
class ExchangeRequestSecurity
{
    public function __construct(
        private ExchangeEcc $ecc,
        private MacService $mac,
        private KeyManager $keys,
    ) {
        $this->keys->ensureSystemKeys();
    }

    public function encryptDetails(array $details): string
    {
        return $this->ecc->encrypt(json_encode($details, JSON_THROW_ON_ERROR), $this->keys->eccPublic());
    }

    public function decryptDetails(string $encryptedDetails, ?string $status = null, ?string $mac = null, ?string $completionPayload = null): array
    {
        if ($status !== null && $mac !== null) {
            $this->assertIntegrity($encryptedDetails, $status, $mac, $completionPayload);
        }

        $payload = $this->ecc->decrypt($encryptedDetails, $this->keys->resolveEccPrivateFromCipher($encryptedDetails));
        $details = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($details)) {
            throw new RuntimeException('Decrypted exchange-request details are invalid.');
        }
        return $details;
    }

    public function generateMac(string $encryptedDetails, string $status, ?string $completionPayload = null): string
    {
        return $this->mac->generate($this->mac->join([
            $encryptedDetails,
            $status,
            $completionPayload ?? '',
        ]));
    }

    public function verifyMac(string $encryptedDetails, string $status, string $mac, ?string $completionPayload = null): bool
    {
        return $this->mac->verify($this->mac->join([
            $encryptedDetails,
            $status,
            $completionPayload ?? '',
        ]), $mac);
    }

    public function encryptCompletion(array $record): string
    {
        return $this->ecc->encrypt(json_encode($record, JSON_THROW_ON_ERROR), $this->keys->eccPublic());
    }

    public function decryptCompletion(string $encrypted): array
    {
        $payload = $this->ecc->decrypt($encrypted, $this->keys->resolveEccPrivateFromCipher($encrypted));
        $record = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($record)) {
            throw new RuntimeException('Decrypted completion record is invalid.');
        }
        return $record;
    }

    public function assertIntegrity(string $encryptedDetails, string $status, ?string $mac, ?string $completionPayload = null): void
    {
        if (!$mac || !$this->verifyMac($encryptedDetails, $status, $mac, $completionPayload)) {
            throw new RuntimeException('Exchange request integrity check failed.');
        }
    }
}

namespace App\Services;

use RuntimeException;

/**
 * From-scratch ECC (NIST P-256) for listings, posts, notifications, and trade requests.
 * ECIES-style: C1 = kG, S = kQ, C2 = M XOR keystream(S.x). Not used for profiles (RSA).
 */
class ExchangeEcc
{
    private const VERSION = 'ecc_v1';
    private const WINDOW = 4;

    private CryptoMath $math;
    private mixed $p;
    private mixed $a;
    private mixed $gx;
    private mixed $gy;
    private mixed $n;
    private mixed $zero;
    private mixed $one;
    private mixed $two;
    private mixed $three;
    private mixed $four;
    private mixed $eight;

    /** @var array<int, array{0:mixed,1:mixed,2:mixed}>|null */
    private ?array $generatorTable = null;

    /** @var array<string, array<int, array{0:mixed,1:mixed,2:mixed}>> */
    private array $pointTables = [];

    public function __construct(?CryptoMath $math = null)
    {
        $this->math = $math ?? new CryptoMath();
        $m = $this->math;

        $this->p = $m->fromDec('115792089210356248762697446949407573530086143415290314195533631308867097853951');
        $this->a = $m->fromDec('115792089210356248762697446949407573530086143415290314195533631308867097853948');
        $this->gx = $m->fromDec('48439561293906451759052585252797914202762949521015779985241413554433676408614');
        $this->gy = $m->fromDec('36134250956749795798585127919587881956611106672985015071877198253568414405109');
        $this->n = $m->fromDec('115792089210356248762697446949407573529996955224135760342422259061068512044369');
        $this->zero = $m->fromDec('0');
        $this->one = $m->fromDec('1');
        $this->two = $m->fromDec('2');
        $this->three = $m->fromDec('3');
        $this->four = $m->fromDec('4');
        $this->eight = $m->fromDec('8');
    }

    /** @return array{version:string,d:string,qx:string,qy:string,curve:string} */
    public function generateKeyPair(): array
    {
        $d = $this->randomScalar();
        $Q = $this->scalarMult($d, [$this->gx, $this->gy], true);

        return [
            'version' => self::VERSION,
            'curve' => 'P-256',
            'd' => $this->math->toDec($d),
            'qx' => $this->math->toDec($Q[0]),
            'qy' => $this->math->toDec($Q[1]),
        ];
    }

    public function encrypt(string $plaintext, array $publicKey): string
    {
        $Q = [$this->math->fromDec((string) $publicKey['qx']), $this->math->fromDec((string) $publicKey['qy'])];
        $k = $this->randomScalar();
        $C1 = $this->scalarMult($k, [$this->gx, $this->gy], true);
        $S = $this->scalarMult($k, $Q, false);
        $stream = $this->keystream($this->math->toDec($S[0]), strlen($plaintext));
        $c2 = $plaintext ^ $stream;

        $version = $publicKey['version'] ?? self::VERSION;

        return $version . ':' . base64_encode(json_encode([
            'c1x' => $this->math->toDec($C1[0]),
            'c1y' => $this->math->toDec($C1[1]),
            'c2' => base64_encode($c2),
        ], JSON_THROW_ON_ERROR));
    }

    public function decrypt(string $ciphertext, array $privateKey): string
    {
        $parts = explode(':', $ciphertext, 2);
        if (count($parts) !== 2) {
            throw new RuntimeException('Invalid ECC ciphertext format.');
        }

        $payload = json_decode(base64_decode($parts[1], true) ?: '', true);
        if (!is_array($payload) || !isset($payload['c1x'], $payload['c1y'], $payload['c2'])) {
            throw new RuntimeException('Corrupt ECC ciphertext.');
        }

        $C1 = [$this->math->fromDec((string) $payload['c1x']), $this->math->fromDec((string) $payload['c1y'])];
        $d = $this->math->fromDec((string) $privateKey['d']);
        $S = $this->scalarMult($d, $C1, false);
        $c2 = base64_decode($payload['c2'], true);
        if ($c2 === false) {
            throw new RuntimeException('Corrupt ECC payload.');
        }

        $stream = $this->keystream($this->math->toDec($S[0]), strlen($c2));

        return $c2 ^ $stream;
    }

    public function publicOnly(array $key): array
    {
        return [
            'version' => $key['version'] ?? self::VERSION,
            'curve' => $key['curve'] ?? 'P-256',
            'qx' => $key['qx'],
            'qy' => $key['qy'],
        ];
    }

    /**
     * @param array{0:mixed,1:mixed} $P affine
     * @return array{0:mixed,1:mixed}
     */
    private function scalarMult(mixed $k, array $P, bool $isGenerator): array
    {
        $table = $isGenerator ? $this->generatorWindowTable() : $this->windowTable($P);
        $hex = ltrim($this->math->toHex($k), '0');
        if ($hex === '') {
            throw new RuntimeException('ECC scalar multiplication produced infinity.');
        }

        $result = null;
        $len = strlen($hex);
        for ($i = 0; $i < $len; $i++) {
            $nibble = hexdec($hex[$i]);
            if ($result !== null) {
                $result = $this->jacobianDouble($result);
                $result = $this->jacobianDouble($result);
                $result = $this->jacobianDouble($result);
                $result = $this->jacobianDouble($result);
            }
            if ($nibble !== 0) {
                $result = $result === null ? $table[$nibble] : $this->jacobianAdd($result, $table[$nibble]);
            }
        }

        if ($result === null) {
            throw new RuntimeException('ECC scalar multiplication produced infinity.');
        }

        return $this->toAffine($result);
    }

    /** @return array<int, array{0:mixed,1:mixed,2:mixed}> */
    private function generatorWindowTable(): array
    {
        if ($this->generatorTable === null) {
            $this->generatorTable = $this->buildWindowTable([$this->gx, $this->gy]);
        }

        return $this->generatorTable;
    }

    /**
     * @param array{0:mixed,1:mixed} $P
     * @return array<int, array{0:mixed,1:mixed,2:mixed}>
     */
    private function windowTable(array $P): array
    {
        $key = $this->math->toDec($P[0]) . ':' . $this->math->toDec($P[1]);
        if (!isset($this->pointTables[$key])) {
            $this->pointTables[$key] = $this->buildWindowTable($P);
            if (count($this->pointTables) > 32) {
                $this->pointTables = array_slice($this->pointTables, -16, 16, true);
            }
        }

        return $this->pointTables[$key];
    }

    /**
     * @param array{0:mixed,1:mixed} $P
     * @return array<int, array{0:mixed,1:mixed,2:mixed}>
     */
    private function buildWindowTable(array $P): array
    {
        $base = [$P[0], $P[1], $this->one];
        $table = [];
        $table[1] = $base;
        $max = (1 << self::WINDOW) - 1;
        for ($i = 2; $i <= $max; $i++) {
            $table[$i] = ($i % 2 === 0)
                ? $this->jacobianDouble($table[$i / 2])
                : $this->jacobianAdd($table[$i - 1], $base);
        }

        return $table;
    }

    /** @param array{0:mixed,1:mixed,2:mixed} $P */
    private function jacobianDouble(array $P): array
    {
        [$x1, $y1, $z1] = $P;
        if ($this->math->cmp($y1, $this->zero) === 0 || $this->math->cmp($z1, $this->zero) === 0) {
            return [$this->one, $this->one, $this->zero];
        }

        $delta = $this->modMul($z1, $z1);
        $gamma = $this->modMul($y1, $y1);
        $beta = $this->modMul($x1, $gamma);
        $x1d = $this->modSub($x1, $delta);
        $x1s = $this->modAdd($x1, $delta);
        $alpha = $this->modMul($this->three, $this->modMul($x1d, $x1s));
        $x3 = $this->modSub($this->modMul($alpha, $alpha), $this->modMul($this->eight, $beta));
        $z3 = $this->modSub($this->modSub($this->modMul($this->modAdd($y1, $z1), $this->modAdd($y1, $z1)), $gamma), $delta);
        $y3 = $this->modSub(
            $this->modMul($alpha, $this->modSub($this->modMul($this->four, $beta), $x3)),
            $this->modMul($this->eight, $this->modMul($gamma, $gamma))
        );

        return [$x3, $y3, $z3];
    }

    /**
     * @param array{0:mixed,1:mixed,2:mixed}|null $P
     * @param array{0:mixed,1:mixed,2:mixed}|null $Q
     * @return array{0:mixed,1:mixed,2:mixed}|null
     */
    private function jacobianAdd(?array $P, ?array $Q): ?array
    {
        if ($P === null || $this->math->cmp($P[2], $this->zero) === 0) {
            return $Q;
        }
        if ($Q === null || $this->math->cmp($Q[2], $this->zero) === 0) {
            return $P;
        }

        [$x1, $y1, $z1] = $P;
        [$x2, $y2, $z2] = $Q;

        $z1z1 = $this->modMul($z1, $z1);
        $z2z2 = $this->modMul($z2, $z2);
        $u1 = $this->modMul($x1, $z2z2);
        $u2 = $this->modMul($x2, $z1z1);
        $s1 = $this->modMul($y1, $this->modMul($z2, $z2z2));
        $s2 = $this->modMul($y2, $this->modMul($z1, $z1z1));
        $h = $this->modSub($u2, $u1);
        $r = $this->modSub($s2, $s1);

        if ($this->math->cmp($h, $this->zero) === 0) {
            if ($this->math->cmp($r, $this->zero) === 0) {
                return $this->jacobianDouble($P);
            }

            return [$this->one, $this->one, $this->zero];
        }

        $hh = $this->modMul($h, $h);
        $hhh = $this->modMul($h, $hh);
        $v = $this->modMul($u1, $hh);
        $x3 = $this->modSub($this->modSub($this->modMul($r, $r), $hhh), $this->modMul($this->two, $v));
        $y3 = $this->modSub($this->modMul($r, $this->modSub($v, $x3)), $this->modMul($s1, $hhh));
        $z3 = $this->modMul($this->modMul($h, $z1), $z2);

        return [$x3, $y3, $z3];
    }

    /**
     * @param array{0:mixed,1:mixed,2:mixed} $P
     * @return array{0:mixed,1:mixed}
     */
    private function toAffine(array $P): array
    {
        if ($this->math->cmp($P[2], $this->zero) === 0) {
            throw new RuntimeException('ECC scalar multiplication produced infinity.');
        }

        $zInv = $this->math->invert($P[2], $this->p);
        if ($zInv === null) {
            throw new RuntimeException('ECC affine conversion failed.');
        }
        $zInv2 = $this->modMul($zInv, $zInv);
        $zInv3 = $this->modMul($zInv2, $zInv);

        return [
            $this->modMul($P[0], $zInv2),
            $this->modMul($P[1], $zInv3),
        ];
    }

    private function keystream(string $sx, int $length): string
    {
        $out = '';
        $counter = 0;
        $seed = $sx;
        while (strlen($out) < $length) {
            $out .= hash('sha256', $seed . ':' . $counter, true);
            $counter++;
        }

        return substr($out, 0, $length);
    }

    private function randomScalar(): mixed
    {
        do {
            $k = $this->math->mod($this->math->fromBytes(random_bytes(32)), $this->n);
        } while ($this->math->cmp($k, $this->zero) === 0);

        return $k;
    }

    private function modAdd(mixed $a, mixed $b): mixed
    {
        return $this->math->mod($this->math->add($a, $b), $this->p);
    }

    private function modSub(mixed $a, mixed $b): mixed
    {
        return $this->math->mod($this->math->sub($a, $b), $this->p);
    }

    private function modMul(mixed $a, mixed $b): mixed
    {
        return $this->math->mod($this->math->mul($a, $b), $this->p);
    }
}
