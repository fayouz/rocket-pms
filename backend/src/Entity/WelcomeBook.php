<?php

namespace App\Entity;

use App\Repository\WelcomeBookRepository;
use Doctrine\ORM\Mapping as ORM;
use Rocket\Core\Entity\TrackedTrait;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\HttpKernel\Exception\HttpException;
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
    /** French is the default language (the "content" column); the others are optional, per section, falling back to French. */
    public const DEFAULT_LANGUAGE = 'fr';
    public const LANGUAGES = ['fr', 'en', 'es', 'de', 'it'];
    public const LAYOUTS = ['tabs', 'columns'];
    public const DEFAULT_ACCENT = '#0f766e';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Property $property;

    /** @var array<string, string> section => text */
    #[ORM\Column(type: 'json')]
    private array $content = [];

    /** @var array<string, array<string, string>> language (en, es, de, it) => section => text */
    #[ORM\Column(type: 'json')]
    private array $translations = [];

    /** @var array{accent?: string, coverUrl?: string|null, coverDocumentRef?: string|null, layout?: string} visual customisation */
    #[ORM\Column(type: 'json')]
    private array $style = [];

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
                $this->content[$s] = self::clean((string) $data[$s]);
            }
        }

        return $this;
    }

    /** @return array<string, array<string, string>> translated sections (non-empty only) per optional language */
    public function getTranslations(): array
    {
        $out = [];
        foreach (array_diff(self::LANGUAGES, [self::DEFAULT_LANGUAGE]) as $lang) {
            $out[$lang] = array_filter(array_map('strval', $this->translations[$lang] ?? []), static fn (string $t) => '' !== $t);
        }

        return $out;
    }

    /** Languages the book can be shown in: French plus every language with at least one translated section. @return list<string> */
    public function getLanguages(): array
    {
        return array_values(array_merge([self::DEFAULT_LANGUAGE], array_keys(array_filter($this->getTranslations()))));
    }

    /** Content in a language, section by section, French when the section is not translated. @return array<string, string> */
    public function getContentIn(string $lang): array
    {
        $content = $this->getContent();
        foreach ($this->getTranslations()[$lang] ?? [] as $section => $text) {
            if (isset($content[$section])) {
                $content[$section] = $text;
            }
        }

        return $content;
    }

    /** @param array<mixed> $data language => section => text; unknown languages and sections ignored */
    public function updateTranslations(array $data): static
    {
        foreach (array_diff(self::LANGUAGES, [self::DEFAULT_LANGUAGE]) as $lang) {
            if (!\is_array($data[$lang] ?? null)) {
                continue;
            }
            foreach (self::SECTIONS as $s) {
                if (\array_key_exists($s, $data[$lang]) && (\is_string($data[$lang][$s]) || null === $data[$lang][$s])) {
                    $this->translations[$lang][$s] = self::clean((string) $data[$lang][$s]);
                }
            }
        }

        return $this;
    }

    /** @return array{accent: string, coverUrl: string|null, coverDocumentRef: string|null, layout: string} */
    public function getStyle(): array
    {
        return [
            'accent' => (string) ($this->style['accent'] ?? self::DEFAULT_ACCENT),
            'coverUrl' => $this->style['coverUrl'] ?? null,
            'coverDocumentRef' => $this->style['coverDocumentRef'] ?? null,
            'layout' => (string) ($this->style['layout'] ?? 'tabs'),
        ];
    }

    /**
     * Partial update of the visual customisation, validated: accent "#rrggbb", cover either an https URL or a Rocket
     * Place document id of the property's place (served by the public cover endpoint), layout tabs|columns.
     *
     * @param array<mixed> $data
     */
    public function updateStyle(array $data): static
    {
        $style = $this->getStyle();
        if (\array_key_exists('accent', $data)) {
            $accent = strtolower(trim((string) $data['accent']));
            if (!preg_match('/^#[0-9a-f]{6}$/', $accent)) {
                throw new HttpException(422, 'accent : couleur invalide (#rrggbb).');
            }
            $style['accent'] = $accent;
        }
        if (\array_key_exists('coverUrl', $data)) {
            $url = trim((string) $data['coverUrl']);
            if ('' !== $url && (mb_strlen($url) > 500 || !str_starts_with($url, 'https://') || false === filter_var($url, \FILTER_VALIDATE_URL) || preg_match('/[\s"\'<>]/', $url))) {
                throw new HttpException(422, 'coverUrl : adresse https:// requise.');
            }
            $style['coverUrl'] = '' === $url ? null : $url;
            if (null !== $style['coverUrl']) {
                $style['coverDocumentRef'] = null;
            }
        }
        if (\array_key_exists('coverDocumentRef', $data)) {
            $ref = trim((string) $data['coverDocumentRef']);
            if ('' !== $ref && !preg_match('/^file:[\w-]{1,200}$/', $ref)) {
                throw new HttpException(422, 'coverDocumentRef : document invalide.');
            }
            $style['coverDocumentRef'] = '' === $ref ? null : $ref;
            if (null !== $style['coverDocumentRef']) {
                $style['coverUrl'] = null;
            }
        }
        if (\array_key_exists('layout', $data)) {
            if (!\in_array($data['layout'], self::LAYOUTS, true)) {
                throw new HttpException(422, 'layout : disposition inconnue (tabs ou columns).');
            }
            $style['layout'] = $data['layout'];
        }
        $this->style = $style;

        return $this;
    }

    /** Picks the language of a visitor: ?lang= when offered, else the first offered one of Accept-Language, else French. @param list<string> $accepted */
    public function pickLanguage(?string $requested, array $accepted): string
    {
        $offered = $this->getLanguages();
        foreach (array_merge([(string) $requested], $accepted) as $candidate) {
            $lang = strtolower(substr(trim($candidate), 0, 2));
            if (\in_array($lang, $offered, true)) {
                return $lang;
            }
        }

        return self::DEFAULT_LANGUAGE;
    }

    private static function clean(string $text): string
    {
        return mb_substr(trim((string) preg_replace('/[^\P{Cc}\n\t]/u', '', $text)), 0, self::MAX_LENGTH);
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
