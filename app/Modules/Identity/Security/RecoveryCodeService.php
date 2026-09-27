<?php

declare(strict_types=1);

namespace WBS\Identity\Security;

use CodeIgniter\Database\BaseConnection;
use WBS\Shared\Support\Clock;
use WBS\Shared\Support\Uuid;

/**
 * MFA recovery codes (SRS adaptive MFA). Codes are high-entropy, displayed
 * once, stored ONLY as HMAC hashes, single-use, and regenerated as a set.
 * The plaintext is returned to the caller exactly once at generation time and
 * never persisted.
 */
final class RecoveryCodeService
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly string $hmacKey,
    ) {
    }

    /**
     * Generate a fresh set, replacing any prior codes for the user.
     *
     * @return list<string> plaintext codes (show once)
     */
    public function generate(string $userId, int $count = 10): array
    {
        $this->db->table('recovery_codes')->where('user_id', $userId)->delete();

        $now   = $this->clock->nowUtcString();
        $codes = [];
        foreach (range(1, $count) as $_) {
            $code    = $this->randomCode();
            $codes[] = $code;
            $this->db->table('recovery_codes')->insert([
                'id'         => Uuid::v7(),
                'user_id'    => $userId,
                'code_hash'  => $this->hash($code),
                'used_at'    => null,
                'created_at' => $now,
            ]);
        }

        return $codes;
    }

    /** Consume a code if valid + unused. Returns true on success. */
    public function consume(string $userId, string $code): bool
    {
        $hash = $this->hash($this->normalize($code));
        $row  = $this->db->table('recovery_codes')
            ->where('user_id', $userId)
            ->where('code_hash', $hash)
            ->where('used_at', null)
            ->get()->getRowArray();

        if ($row === null) {
            return false;
        }

        $this->db->table('recovery_codes')->where('id', $row['id'])->update([
            'used_at' => $this->clock->nowUtcString(),
        ]);

        return true;
    }

    public function remaining(string $userId): int
    {
        return $this->db->table('recovery_codes')
            ->where('user_id', $userId)->where('used_at', null)
            ->countAllResults();
    }

    private function randomCode(): string
    {
        $raw = strtolower(bin2hex(random_bytes(5))); // 10 hex chars

        return substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
    }

    private function normalize(string $code): string
    {
        return strtolower(trim($code));
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $this->normalize($code), $this->hmacKey);
    }
}
