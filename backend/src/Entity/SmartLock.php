<?php

namespace App\Entity;

use App\Repository\SmartLockRepository;
use Doctrine\ORM\Mapping as ORM;
use Rocket\Core\Entity\TrackedTrait;

/**
 * A Nuki smart lock and the property it opens. The live state (locked, battery, log) is read from Nuki on demand;
 * only the link to the property is stored here.
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
}
