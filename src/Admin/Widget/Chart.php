<?php

namespace Base\Ledger\Admin\Widget;

/**
 * The geometry of the bank widgets' charts, drawn as markup (inline SVG and
 * CSS) by their templates: a donut's arcs, a nice scale's top and ticks,
 * bar heights and a line's points, all as percentages of their box.
 *
 * Why no chart library: omnibase/admin's analytics cards draw with Chart.js,
 * built into omnisense/omnibase's admin-charts entry with the line
 * controller only - a donut or bars would mean rebuilding that core's
 * assets. Markup also needs no script to start in the three ways a card
 * arrives (the page, the palette's inserted fragment, an in-admin
 * navigation), and follows the light and dark tokens as the theme switches.
 */
final class Chart
{
    /** A circle of radius 100 / 2π: its circumference is 100, so a dash length is a percentage. */
    public const RADIUS = 15.9155;

    /**
     * Each value's arc: its share in %, the dash drawn (the share less a gap
     * between arcs) and the offset that starts it where the previous ended,
     * from twelve o'clock.
     *
     * @param list<int|float> $values
     *
     * @return list<array{share: float, dash: float, offset: float}>
     */
    public static function donut(array $values, float $gap = 1.2): array
    {
        $total = array_sum(array_map(static fn ($v) => max(0, $v), $values));
        $arcs = [];
        $start = 0.0;
        foreach ($values as $value) {
            $share = $total > 0 ? max(0, $value) / $total * 100 : 0.0;
            $dash = \count(array_filter($values, static fn ($v) => $v > 0)) > 1 ? max(0.0, $share - $gap) : $share;
            $arcs[] = ['share' => $share, 'dash' => round($dash, 3), 'offset' => round(25 - $start, 3)];
            $start += $share;
        }

        return $arcs;
    }

    /**
     * A scale from zero that ends on a round number above $max, with its ticks.
     *
     * @return array{max: int, ticks: list<int>}
     */
    public static function scale(int|float $max, int $steps = 2): array
    {
        if ($max <= 0) {
            return ['max' => 1, 'ticks' => [0]];
        }
        $raw = $max / $steps;
        $magnitude = 10 ** floor(log10($raw));
        foreach ([1, 2, 2.5, 5, 10] as $nice) {
            if ($nice * $magnitude >= $raw) {
                $step = $nice * $magnitude;
                break;
            }
        }
        $step = (int) ceil($step ?? $raw);
        $ticks = [];
        for ($i = 0; $i <= $steps; ++$i) {
            $ticks[] = $i * $step;
        }

        return ['max' => $steps * $step, 'ticks' => $ticks];
    }

    /**
     * The value a bar chart's scale should reach: the largest, unless one or
     * two values tower over the rest (each more than $ratio times the next):
     * then the largest of the others, so that a single heavy month does not
     * flatten every other bar. The towering bars are drawn clipped at the
     * top of the scale, marked, their value given in full.
     *
     * @param list<int|float> $values
     */
    public static function robustMax(array $values, float $ratio = 2.2, int $outliers = 2): int|float
    {
        rsort($values);
        $values = array_values(array_filter($values, static fn ($v) => $v > 0));
        if ([] === $values) {
            return 0;
        }
        $i = 0;
        while ($i < $outliers && isset($values[$i + 1]) && $values[$i] > $ratio * $values[$i + 1]) {
            ++$i;
        }

        return $values[$i];
    }

    /** $value as a percentage of $max, kept within 0..100. */
    public static function percent(int|float $value, int|float $max): float
    {
        return $max > 0 ? round(min(100, max(0, $value / $max * 100)), 2) : 0.0;
    }

    /**
     * A line through $values in a 100 x 100 box (y down), its area's path
     * closed on the box's bottom, and the range it spans (the lowest and
     * highest value, padded so the line never touches the edges).
     *
     * With $centered, each point sits at the middle of its slot, under a
     * bar chart's columns.
     *
     * @param list<int|float> $values
     *
     * @return array{points: string, area: string, min: int|float, max: int|float, coords: list<array{x: float, y: float}>}
     */
    public static function line(array $values, float $padding = 0.08, bool $centered = false): array
    {
        $n = \count($values);
        if (0 === $n) {
            return ['points' => '', 'area' => '', 'min' => 0, 'max' => 0, 'coords' => []];
        }
        $min = min($values);
        $max = max($values);
        $span = $max - $min ?: max(abs($max), 1);
        $low = $min - $span * $padding;
        $high = $max + $span * $padding;
        $coords = [];
        foreach (array_values($values) as $i => $value) {
            $x = $centered ? ($i + 0.5) / $n * 100 : (1 === $n ? 50.0 : $i / ($n - 1) * 100);
            $y = 100 - ($value - $low) / ($high - $low) * 100;
            $coords[] = ['x' => round($x, 2), 'y' => round($y, 2)];
        }
        $points = implode(' ', array_map(static fn ($c) => $c['x'].','.$c['y'], $coords));

        $first = $coords[0]['x'];
        $last = end($coords)['x'];

        return ['points' => $points, 'area' => 'M'.$first.',100 L'.str_replace(' ', ' L', $points).' L'.$last.',100 Z', 'min' => $min, 'max' => $max, 'coords' => $coords];
    }
}
