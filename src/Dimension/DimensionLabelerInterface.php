<?php

namespace Base\Ledger\Dimension;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Names the values of an analytic axis: the ledger stores unit=12 and does
 * not know what a unit is; the application does (omnibase/estate names its
 * units). Autoconfigured: a service implementing it is asked by Dimensions.
 */
#[AutoconfigureTag(self::TAG)]
interface DimensionLabelerInterface
{
    public const TAG = 'ledger.dimension_labeler';

    public function supports(string $axis): bool;

    /**
     * @param list<string> $values
     *
     * @return array<string, string> value => label, for the values it knows
     */
    public function labels(string $axis, array $values): array;
}
