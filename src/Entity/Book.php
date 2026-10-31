<?php

namespace Base\Ledger\Entity;

use Base\Ledger\Enum\VatRegime;
use Base\Ledger\Repository\BookRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One legal entity's accounts (a company, an SCI): its chart, journals,
 * parties, entries and bank accounts all hang off it. Entries dated on or
 * before $lockedUntil can no longer be posted, changed or reversed - the
 * period is closed, declared, or handed to the accountant.
 *
 * $vatRegime says how its VAT is declared: none by default (a property
 * company letting homes is not liable), the VAT return (Report\VatReturn)
 * reading its taxed lines otherwise.
 */
#[ORM\Entity(repositoryClass: BookRepository::class)]
#[ORM\Table(name: 'ledger_book')]
class Book
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name;

    /** The SIREN (9 digits): the FEC's file name starts with it. */
    #[ORM\Column(length: 9, nullable: true)]
    private ?string $siren;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $lockedUntil = null;

    #[ORM\Column(length: 20, enumType: VatRegime::class, options: ['default' => 'none'])]
    private VatRegime $vatRegime = VatRegime::NONE;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, ?string $siren = null, string $currency = 'EUR')
    {
        $this->name = $name;
        $this->setSiren($siren);
        $this->currency = strtoupper($currency);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(?string $name): self { $this->name = (string) $name; return $this; }
    public function getSiren(): ?string { return $this->siren; }
    public function setSiren(?string $siren): self { $siren = null === $siren ? null : preg_replace('/\D/', '', $siren); $this->siren = '' === $siren ? null : $siren; return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(?string $currency): self { $this->currency = strtoupper(trim((string) $currency)) ?: 'EUR'; return $this; }
    public function getLockedUntil(): ?\DateTimeImmutable { return $this->lockedUntil; }
    public function setLockedUntil(?\DateTimeImmutable $lockedUntil): self { $this->lockedUntil = $lockedUntil?->setTime(0, 0); return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getVatRegime(): VatRegime { return $this->vatRegime; }
    public function setVatRegime(VatRegime $vatRegime): self { $this->vatRegime = $vatRegime; return $this; }

    /** Whether $date falls in the locked period (on or before lockedUntil). */
    public function isLocked(\DateTimeInterface $date): bool
    {
        return null !== $this->lockedUntil && $date->format('Y-m-d') <= $this->lockedUntil->format('Y-m-d');
    }

    public function getBook(): self
    {
        return $this;
    }
}
