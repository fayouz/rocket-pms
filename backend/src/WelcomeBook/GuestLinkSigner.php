<?php

namespace App\WelcomeBook;

use App\Entity\WelcomeBook;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Guest link of one booking, stateless: base64url(property uuid (16 bytes) . booking id (8 bytes) . HMAC-SHA256
 * truncated to 16 bytes (128 bits)), keyed by the application secret and the book's guest salt. Nothing is stored
 * per booking; rotating the salt revokes every guest link of the property.
 */
final class GuestLinkSigner
{
    public function __construct(#[Autowire('%kernel.secret%')] private readonly string $secret)
    {
    }

    public function sign(WelcomeBook $book, int $bookingId): string
    {
        $payload = $book->getProperty()->getId()->toBinary().pack('J', $bookingId);

        return self::b64($payload.$this->mac($book, $payload));
    }

    /** @return array{0: string, 1: int}|null property id (RFC 4122) and booking id, unverified: call ::verify with the book */
    public static function parse(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{54}$/', $token)) {
            return null;
        }
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        if (false === $raw || 40 !== \strlen($raw)) {
            return null;
        }
        $id = unpack('J', substr($raw, 16, 8))[1];

        return [Uuid::fromBinary(substr($raw, 0, 16))->toRfc4122(), $id];
    }

    public function verify(WelcomeBook $book, string $token): bool
    {
        $raw = base64_decode(strtr($token, '-_', '+/'), true);

        return false !== $raw && 40 === \strlen($raw) && hash_equals($this->mac($book, substr($raw, 0, 24)), substr($raw, 24));
    }

    private function mac(WelcomeBook $book, string $payload): string
    {
        return substr(hash_hmac('sha256', $payload, $this->secret.'|welcome-book|'.$book->getGuestSalt(), true), 0, 16);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
