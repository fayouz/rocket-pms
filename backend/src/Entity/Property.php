<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\PropertyRepository;
use Doctrine\ORM\Mapping as ORM;
use Rocket\Core\Entity\TrackedTrait;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A rental property (logement), usually linked to a Lodgify property: bookings, messaging and prices come from Lodgify,
 * everything physical (locks, access grants, domotique, documents, stock) from its place in Rocket Place. Created automatically for every Lodgify property (POST /api/properties/sync), renamable here.
 */
#[ORM\Entity(repositoryClass: PropertyRepository::class)]
#[UniqueEntity(fields: ['lodgifyPropertyId'], message: 'property.lodgify_taken')]
#[ApiResource(
    operations: [
        new GetCollection(uriTemplate: '/properties'),
        new Get(uriTemplate: '/properties/{id}'),
        new Post(uriTemplate: '/properties', security: "is_granted('PMS_MANAGE')"),
        new Patch(uriTemplate: '/properties/{id}', security: "is_granted('PMS_MANAGE')"),
    ],
    normalizationContext: ['groups' => ['property:read', 'tracking']],
    denormalizationContext: ['groups' => ['property:write']],
    security: "is_granted('PMS_READ')",
    order: ['name' => 'ASC'],
    paginationEnabled: false,
)]
class Property
{
    /** Colour marker shown next to the property name everywhere ('' = none). */
    public const COLORS = ['', 'red', 'orange', 'amber', 'green', 'teal', 'blue', 'violet', 'pink'];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['property:read'])]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    #[Groups(['property:read', 'property:write'])]
    private string $name = '';

    #[ORM\Column(nullable: true, unique: true)]
    #[Groups(['property:read', 'property:write'])]
    private ?int $lodgifyPropertyId = null;

    /** Name of the property in Lodgify (read-only, refreshed at each sync). */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['property:read'])]
    private ?string $lodgifyName = null;

    #[ORM\Column(length: 16)]
    #[Assert\Choice(choices: self::COLORS, message: 'property.color')]
    #[Groups(['property:read', 'property:write'])]
    private string $color = '';

    #[ORM\Column(nullable: true)]
    #[Groups(['property:read'])]
    private ?float $latitude = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['property:read'])]
    private ?float $longitude = null;

    /**
     * Id of the place of this property in Rocket Place (locks, access grants, domotique, documents, stock). Set by an
     * admin through PUT /api/properties/{id}/place (checked against Rocket Place), never through PATCH.
     */
    #[ORM\Column(length: 36, nullable: true)]
    #[Groups(['property:read'])]
    private ?string $placeId = null;

    use TrackedTrait;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = trim($name); return $this; }
    public function getLodgifyPropertyId(): ?int { return $this->lodgifyPropertyId; }
    public function setLodgifyPropertyId(?int $id): static { $this->lodgifyPropertyId = $id; return $this; }
    public function getLodgifyName(): ?string { return $this->lodgifyName; }
    public function setLodgifyName(?string $name): static { $this->lodgifyName = $name; return $this; }
    public function getColor(): string { return $this->color; }
    public function setColor(string $color): static { $this->color = $color; return $this; }
    public function getLatitude(): ?float { return $this->latitude; }
    public function getLongitude(): ?float { return $this->longitude; }
    public function setCoordinates(?float $latitude, ?float $longitude): static { $this->latitude = $latitude; $this->longitude = $longitude; return $this; }
    public function getPlaceId(): ?string { return $this->placeId; }
    public function setPlaceId(?string $id): static { $this->placeId = $id; return $this; }
}
