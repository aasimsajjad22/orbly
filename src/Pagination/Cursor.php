<?php

namespace App\Pagination;

/**
 * An opaque pagination cursor: a timestamp plus an id.
 *
 * Base64-encoded so clients treat it as a token rather than constructing
 * one. That is presentation, not security — anyone can decode it, and
 * nothing here needs to be secret. The point is that we can change the
 * internal format later without breaking clients that just echo it back.
 */
final readonly class Cursor
{
    public function __construct(
        public \DateTimeImmutable $createdAt,
        public int $id,
    ) {
    }

    public function encode(): string
    {
        // The id is the tiebreaker for posts sharing a timestamp.
        return base64_encode($this->createdAt->format('Y-m-d\TH:i:s.u').'|'.$this->id);
    }

    /**
     * @throws \InvalidArgumentException on anything malformed
     */
    public static function decode(string $encoded): self
    {
        $raw = base64_decode($encoded, true);

        // strict mode: base64_decode returns false on invalid input rather
        // than silently mangling it.
        if ($raw === false) {
            throw new \InvalidArgumentException('Invalid cursor.');
        }

        $parts = explode('|', $raw);

        if (count($parts) !== 2) {
            throw new \InvalidArgumentException('Invalid cursor.');
        }

        [$timestamp, $id] = $parts;

        $date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.u', $timestamp);

        if ($date === false || !ctype_digit($id)) {
            throw new \InvalidArgumentException('Invalid cursor.');
        }

        return new self($date, (int) $id);
    }
}
