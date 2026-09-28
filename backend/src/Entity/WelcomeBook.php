<?php

namespace App\Entity;

use App\Repository\WelcomeBookRepository;
use Doctrine\ORM\Mapping as ORM;
use Rocket\Core\Entity\TrackedTrait;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Welcome book (livret d'accueil) of a property, ported from LoussaHousing: free-text sections edited by the host,
 * shown to the guest on a public page (per-booking link, App\WelcomeBook\GuestLinkSigner) and on the kiosk TV screen
 * (per-property token). The TV token and the guest-link salt are rotatable: rotating revokes every link at once.
 */
#[ORM\Entity(repositoryClass: WelcomeBookRepository::class)]
class WelcomeBook
{
    /** Editable sections, in display order. Each one is optional: a book fills up progressively. */
    public const SECTIONS = ['welcomeText', 'wifiSsid', 'wifiPassword', 'checkinInfo', 'checkoutInfo', 'accessDirections', 'houseRules', 'contacts', 'localTips', 'faq'];
    public const MAX_LENGTH = 4000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Property $property;

    /** @var array<string, string> section => text */
    #[ORM\Column(type: 'json')]
    private array $content = [];

    #[ORM\Column(length: 64, unique: true)]
    private string $tvToken;

    #[ORM\Column(length: 64)]
    private string $guestSalt;

    use TrackedTrait;

    public function __construct(Property $property)
    {
        $this->id = Uuid::v7();
        $this->property = $property;
        $this->rotateTvToken();
        $this->rotateGuestSalt();
    }

    public function getId(): Uuid { return $this->id; }
    public function getProperty(): Property { return $this->property; }
    public function getTvToken(): string { return $this->tvToken; }
    public function getGuestSalt(): string { return $this->guestSalt; }

    /** @return array<string, string> every section, '' when empty */
    public function getContent(): array
    {
        return array_combine(self::SECTIONS, array_map(fn (string $s) => (string) ($this->content[$s] ?? ''), self::SECTIONS));
    }

    /** Merges the known sections of $data (unknown keys ignored), trimmed, control characters removed, bounded. @param array<mixed> $data */
    public function updateContent(array $data): static
    {
        foreach (self::SECTIONS as $s) {
            if (\array_key_exists($s, $data) && (\is_string($data[$s]) || null === $data[$s])) {
                $this->content[$s] = mb_substr(trim((string) preg_replace('/[^\P{Cc}\n\t]/u', '', (string) $data[$s])), 0, self::MAX_LENGTH);
            }
        }

        return $this;
    }

    /** 256-bit random token, URL-safe. */
    public function rotateTvToken(): static
    {
        $this->tvToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return $this;
    }

    public function rotateGuestSalt(): static
    {
        $this->guestSalt = bin2hex(random_bytes(16));

        return $this;
    }
}
