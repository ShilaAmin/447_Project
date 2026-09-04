<?php

namespace App\Services;

use RuntimeException;

/**
 * MAC module: HMAC-SHA256 (course-allowed). Callers must verify before decrypt.
 */
class MacService
{
    public function generate(string $message): string
    {
        return hash_hmac('sha256', $message, $this->key());
    }

    public function verify(string $message, string $mac): bool
    {
        if ($mac === '') {
            return false;
        }

        return hash_equals($this->generate($message), $mac);
    }

    public function join(array $parts): string
    {
        return implode('|', $parts);
    }

    private function key(): string
    {
        $key = config('app.key');

        if (!is_string($key) || $key === '') {
            throw new RuntimeException('APP_KEY is required for MAC operations.');
        }

        return $key;
    }
}
