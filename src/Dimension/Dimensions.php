<?php

namespace Base\Ledger\Dimension;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The labels of an axis's values, from the labelers that know the axis
 * (DimensionLabelerInterface); a value nobody names is its own label.
 */
class Dimensions
{
    /** @param iterable<DimensionLabelerInterface> $labelers */
    public function __construct(#[AutowireIterator(DimensionLabelerInterface::TAG)] private readonly iterable $labelers = [])
    {
    }

    /**
     * @param list<string|null> $values
     *
     * @return array<string, string> value => label (null values left out)
     */
    public function labels(string $axis, array $values): array
    {
        $values = array_values(array_unique(array_filter(array_map(fn ($value) => null === $value ? null : (string) $value, $values), fn ($value) => null !== $value)));
        $labels = array_combine($values, $values) ?: [];
        foreach ($this->labelers as $labeler) {
            if ($labeler->supports($axis)) {
                foreach ($labeler->labels($axis, $values) as $value => $label) {
                    if (isset($labels[(string) $value])) {
                        $labels[(string) $value] = $label;
                    }
                }
            }
        }

        return $labels;
    }

    public function label(string $axis, string $value): string
    {
        return $this->labels($axis, [$value])[$value] ?? $value;
    }
}
