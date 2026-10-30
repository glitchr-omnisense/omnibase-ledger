<?php

namespace Base\Ledger\Admin\Widget\Bank;


/**
 * The recurring flows found in the lines - rents, loan instalments,
 * subscriptions, salaries -: each with its usual amount, its rhythm, its
 * next date, how many times it came; what they add up to each month. A
 * transfer between two of the accounts shown (the monthly saving) is left
 * out: it comes back on both, once each way.
 */
final class RecurringWidgetType extends AbstractBankWidgetType
{
    protected const CASH = true;

    public static function getName(): string
    {
        return 'ledger_bank_recurring';
    }

    public function getDefaultLabel(): string
    {
        return 'ledger_widget.recurring';
    }

    public function getDefaultIcon(): ?string
    {
        return 'fa-solid fa-arrows-rotate';
    }

    protected function template(): string
    {
        return 'recurring';
    }

    protected function data(array $accounts, ?array $period, array $params): array
    {
        // A transfer between two of the accounts shown comes back on both, once each way: neither is a flow of the company.
        $all = $this->dashboard->recurring($accounts);
        $flows = array_values(array_filter($all, static function ($flow) use ($all): bool {
            foreach ($all as $other) {
                if ($other !== $flow && $other->getBankAccount() !== $flow->getBankAccount() && $other->getAmount() === -$flow->getAmount() && $other->getRhythm() === $flow->getRhythm() && abs($other->getNextOn()->getTimestamp() - $flow->getNextOn()->getTimestamp()) <= 3 * 86400) {
                    return false;
                }
            }

            return true;
        }));
        $monthly = 0;
        foreach ($flows as $flow) {
            $monthly += (int) round($flow->getAmount() * match ($flow->getRhythm()) {
                'weekly' => 52 / 12,
                'quarterly' => 1 / 3,
                'yearly' => 1 / 12,
                default => 1,
            });
        }

        return ['flows' => \array_slice($flows, 0, 10), 'count' => \count($flows), 'monthly' => $monthly];
    }
}
