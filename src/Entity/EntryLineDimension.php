<?php

namespace Base\Ledger\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * An analytic axis of an entry line and its value: unit=12, owner=5,
 * ownership=3. A table of its own, indexed on the axis and the value, so a
 * report filters and groups in SQL whatever the database. The ledger does
 * not know what a value is: a DimensionLabelerInterface names it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ledger_entry_line_dimension')]
#[ORM\UniqueConstraint(name: 'ledger_entry_line_dimension_axis', fields: ['line', 'axis'])]
#[ORM\Index(name: 'ledger_entry_line_dimension_value', fields: ['axis', 'value'])]
class EntryLineDimension
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EntryLine::class, inversedBy: 'dimensions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EntryLine $line;

    #[ORM\Column(length: 32)]
    private string $axis;

    #[ORM\Column(length: 64)]
    private string $value;

    public function __construct(EntryLine $line, string $axis, string $value)
    {
        $this->line = $line;
        $this->axis = self::axis($axis);
        $this->value = self::value($value);
    }

    public function getId(): ?int { return $this->id; }
    public function getLine(): EntryLine { return $this->line; }
    public function getAxis(): string { return $this->axis; }
    public function getValue(): string { return $this->value; }

    /** An axis is a short lower-case key: letters, digits, underscores. */
    public static function axis(string $axis): string
    {
        $axis = strtolower(trim($axis));
        if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $axis)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an analytic axis: a lower-case key of up to 32 letters, digits and underscores.', $axis));
        }

        return $axis;
    }

    public static function value(string|int $value): string
    {
        $value = trim((string) $value);
        if ('' === $value || mb_strlen($value) > 64) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an analytic value: 1 to 64 characters.', $value));
        }

        return $value;
    }
}
