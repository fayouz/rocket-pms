<?php

namespace App\Entity;

use App\Repository\ConnectorRepository;
use Doctrine\ORM\Mapping as ORM;
use Rocket\Core\Entity\TrackedTrait;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A plugin of the catalogue (App\Domotique\PluginRegistry) configured for a property: several per property allowed
 * (in PMS: Lodgify accounts only; physical connectors live in Rocket Place). Config holds only non-secret values; a field flagged "secret" holds the NAME of a secret of the
 * rocket-core vault (never the value, see App\Domotique\ConnectorSecrets). Admin-only writes (App\Controller\ConnectorController).
 */
#[ORM\Entity(repositoryClass: ConnectorRepository::class)]
class Connector
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Property $property;

    #[ORM\Column(length: 40)]
    private string $pluginId;

    #[ORM\Column(length: 120)]
    private string $name = '';

    /** @var array<string, string> */
    #[ORM\Column(type: 'json')]
    private array $config = [];

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(length: 300, nullable: true)]
    private ?string $lastResult = null;

    use TrackedTrait;

    public function __construct(Property $property, string $pluginId)
    {
        $this->id = Uuid::v7();
        $this->property = $property;
        $this->pluginId = $pluginId;
    }

    public function getId(): Uuid { return $this->id; }
    public function getProperty(): Property { return $this->property; }
    public function getPluginId(): string { return $this->pluginId; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = trim($name); return $this; }
    /** @return array<string, string> */
    public function getConfig(): array { return $this->config; }
    /** @param array<string, string> $config */
    public function setConfig(array $config): static { $this->config = $config; return $this; }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): static { $this->enabled = $enabled; return $this; }
    public function getLastRunAt(): ?\DateTimeImmutable { return $this->lastRunAt; }
    public function getLastResult(): ?string { return $this->lastResult; }

    public function recordRun(string $result): static
    {
        $this->lastRunAt = new \DateTimeImmutable();
        $this->lastResult = mb_substr($result, 0, 300);

        return $this;
    }
}
