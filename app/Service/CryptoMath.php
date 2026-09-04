<?php

namespace App\Services;

/**
 * Fast big-integer helpers. Prefers GMP; falls back to BCMath.
 * Algorithm code (RSA/ECC) stays from-scratch; only the integer engine changes.
 */
final class CryptoMath
{
    public readonly bool $gmp;

    public function __construct()
    {
        $this->gmp = extension_loaded('gmp');
    }

    public function fromDec(string $n): mixed
    {
        return $this->gmp ? gmp_init($n, 10) : $n;
    }

    public function fromHex(string $hex): mixed
    {
        $hex = strtolower(ltrim($hex, '0'));
        if ($hex === '') {
            return $this->fromDec('0');
        }

        return $this->gmp ? gmp_init($hex, 16) : $this->hexToDecBc($hex);
    }

    public function fromBytes(string $bytes): mixed
    {
        if ($bytes === '') {
            return $this->fromDec('0');
        }

        return $this->fromHex(bin2hex($bytes));
    }

    public function toDec(mixed $n): string
    {
        return $this->gmp ? gmp_strval($n, 10) : (string) $n;
    }

    public function toHex(mixed $n): string
    {
        if ($this->gmp) {
            $hex = gmp_strval($n, 16);
            return $hex === '' ? '0' : $hex;
        }

        return $this->decToHexBc((string) $n);
    }

    public function toBytes(mixed $n): string
    {
        if ($this->cmp($n, $this->fromDec('0')) === 0) {
            return "\0";
        }

        $hex = $this->toHex($n);
        if (strlen($hex) % 2 === 1) {
            $hex = '0' . $hex;
        }

        return hex2bin($hex) ?: '';
    }

    public function add(mixed $a, mixed $b): mixed
    {
        return $this->gmp ? gmp_add($a, $b) : bcadd((string) $a, (string) $b);
    }

    public function sub(mixed $a, mixed $b): mixed
    {
        return $this->gmp ? gmp_sub($a, $b) : bcsub((string) $a, (string) $b);
    }

    public function mul(mixed $a, mixed $b): mixed
    {
        return $this->gmp ? gmp_mul($a, $b) : bcmul((string) $a, (string) $b);
    }

    public function mod(mixed $a, mixed $m): mixed
    {
        if ($this->gmp) {
            $r = gmp_mod($a, $m);
            if (gmp_cmp($r, 0) < 0) {
                $r = gmp_add($r, $m);
            }
            return $r;
        }

        $r = bcmod((string) $a, (string) $m);
        if (bccomp($r, '0') < 0) {
            $r = bcadd($r, (string) $m);
        }

        return $r;
    }

    public function div(mixed $a, mixed $b): mixed
    {
        return $this->gmp ? gmp_div_q($a, $b) : bcdiv((string) $a, (string) $b, 0);
    }

    public function powmod(mixed $base, mixed $exp, mixed $mod): mixed
    {
        return $this->gmp
            ? gmp_powm($base, $exp, $mod)
            : bcpowmod((string) $base, (string) $exp, (string) $mod);
    }

    public function cmp(mixed $a, mixed $b): int
    {
        return $this->gmp ? gmp_cmp($a, $b) : bccomp((string) $a, (string) $b);
    }

    public function invert(mixed $a, mixed $m): mixed
    {
        if ($this->gmp) {
            $inv = gmp_invert($a, $m);
            return $inv === false ? null : $inv;
        }

        return $this->modInverseBc((string) $a, (string) $m);
    }

    public function bitLength(mixed $n): int
    {
        if ($this->cmp($n, $this->fromDec('0')) <= 0) {
            return 1;
        }

        if ($this->gmp) {
            return strlen(gmp_strval($n, 2));
        }

        $hex = $this->toHex($n);
        $bits = (strlen($hex) - 1) * 4;
        $first = hexdec($hex[0]);
        while ($first > 0) {
            $bits++;
            $first >>= 1;
        }

        return max(1, $bits);
    }

    private function hexToDecBc(string $hex): string
    {
        $dec = '0';
        $len = strlen($hex);
        $i = 0;
        $lead = $len % 8;
        if ($lead !== 0) {
            $dec = (string) hexdec(substr($hex, 0, $lead));
            $i = $lead;
        }
        for (; $i < $len; $i += 8) {
            $dec = bcmul($dec, '4294967296');
            $dec = bcadd($dec, (string) hexdec(substr($hex, $i, 8)));
        }

        return $dec;
    }

    private function decToHexBc(string $dec): string
    {
        if (bccomp($dec, '0') === 0) {
            return '0';
        }

        $hex = '';
        $base = '4294967296';
        $n = $dec;
        while (bccomp($n, '0') > 0) {
            $rem = (int) bcmod($n, $base);
            $hex = str_pad(dechex($rem), 8, '0', STR_PAD_LEFT) . $hex;
            $n = bcdiv($n, $base, 0);
        }

        $hex = ltrim($hex, '0');
        return $hex === '' ? '0' : $hex;
    }

    private function modInverseBc(string $a, string $m): ?string
    {
        $t = '0';
        $newT = '1';
        $r = $m;
        $newR = bcmod($a, $m);

        while (bccomp($newR, '0') !== 0) {
            $q = bcdiv($r, $newR, 0);
            [$t, $newT] = [$newT, bcsub($t, bcmul($q, $newT))];
            [$r, $newR] = [$newR, bcsub($r, bcmul($q, $newR))];
        }

        if (bccomp($r, '1') > 0) {
            return null;
        }
        if (bccomp($t, '0') < 0) {
            $t = bcadd($t, $m);
        }

        return $t;
    }
}
