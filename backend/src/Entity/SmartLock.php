<?php

namespace App\Entity;

use App\Repository\SmartLockRepository;
use Doctrine\ORM\Mapping as ORM;
use Rocket\Core\Entity\TrackedTrait;

/**
 * A Nuki smart lock (identified by its `nukiId`, the only channel able to write a keypad code) and the property it
 * opens. By default its live state (locked, battery, log) is read from the legacy env-token Nuki API on demand.
 * Optionally, `connector` reroutes the live state to another connector (e.g. Home Assistant, Homey, or a second
 * Nuki account) whose plugin implements App\Lock\LockCapablePluginInterface, with `externalId` the entity/device id
 * in that connector's controller; `codeConnector` similarly reroutes code creation (e.g. a dedicated Nuki
 * connector). See App\Lock\LockProviderRegistry.
 */
#[ORM\Entity(repositoryClass: SmartLockRepository::class)]
class SmartLock
{
    /** Nuki smartlockId. */
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    private string $nukiId;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Property $property = null;

    /** Connector used for the live state (App\Lock\LockProviderRegistry::stateProviderFor); null = legacy Nuki API. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'connector_id', nullable: true, onDelete: 'SET NULL')]
    private ?Connector $connector = null;

    /** Connector used to write keypad/access codes; null = legacy Nuki API. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'code_connector_id', nullable: true, onDelete: 'SET NULL')]
    private ?Connector $codeConnector = null;

    /** Entity/device id of this lock within `connector`'s controller (e.g. a Home Assistant `lock.xxx` entity_id). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $externalId = null;

    use TrackedTrait;

    public function __construct(int|string $nukiId)
    {
        $this->nukiId = (string) $nukiId;
    }

    public function getNukiId(): int { return (int) $this->nukiId; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getProperty(): ?Property { return $this->property; }
    public function setProperty(?Property $property): static { $this->property = $property; return $this; }
    public function getConnector(): ?Connector { return $this->connector; }
    public function setConnector(?Connector $connector): static { $this->connector = $connector; return $this; }
    public function getCodeConnector(): ?Connector { return $this->codeConnector; }
    public function setCodeConnector(?Connector $codeConnector): static { $this->codeConnector = $codeConnector; return $this; }
    public function getExternalId(): ?string { return $this->externalId; }
    public function setExternalId(?string $externalId): static { $this->externalId = $externalId; return $this; }
}
