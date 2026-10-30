<?php

namespace Base\Ledger\Bank;

use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\BankCounterpartyMonth;
use Base\Ledger\Entity\BankDay;
use Base\Ledger\Entity\BankDayCategory;
use Base\Ledger\Entity\BankRecurring;
use Omnibank\Model\TransactionCategory;

/**
 * A bank account's lines folded into what the dashboard reads: one BankDay
 * per day with a movement (in, out, counts, the transfers among them, the
 * closing balance), one
 * BankDayCategory per day and category, one BankCounterpartyMonth per month
 * and counterparty, and the recurring flows (Recurrences). Pure: given the
 * lines, returns the rows; Bank\Aggregates stores them.
 *
 * The closing balances are anchored on the bank's last balance when it gave
 * one (the lines booked after its date added), else on the lines alone, from
 * zero.
 */
final class Aggregator
{
    /**
     * @param list<array{bookedOn: \DateTimeImmutable, amount: int, category: ?string, counterpartyName: ?string, counterpartyIban: ?string, label: string}> $lines
     *
     * @return array{days: list<BankDay>, categories: list<BankDayCategory>, counterparties: list<BankCounterpartyMonth>, recurring: list<BankRecurring>}
     */
    public static function compute(BankAccount $account, array $lines, ?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');
        usort($lines, static fn ($a, $b) => $a['bookedOn'] <=> $b['bookedOn']);

        $days = [];
        $categories = [];
        $counterparties = [];
        $total = 0;
        foreach ($lines as $line) {
            $day = $line['bookedOn']->format('Y-m-d');
            $amount = $line['amount'];
            $total += $amount;
            $in = max($amount, 0);
            $out = max(-$amount, 0);

            $days[$day] ??= [0, 0, 0, 0, 0, 0, 0, 0, 0];
            $days[$day][0] += $in;
            $days[$day][1] += $out;
            $days[$day][2] += $amount > 0 ? 1 : 0;
            $days[$day][3] += $amount < 0 ? 1 : 0;
            $days[$day][4] += $amount;
            // A transfer: categorised so, or carrying the counterparty's IBAN (a SEPA transfer, whatever it pays).
            if (TransactionCategory::TRANSFER->value === $line['category'] || (null !== $line['counterpartyIban'] && '' !== $line['counterpartyIban'])) {
                $days[$day][5] += $in;
                $days[$day][6] += $out;
                $days[$day][7] += $amount > 0 ? 1 : 0;
                $days[$day][8] += $amount < 0 ? 1 : 0;
            }

            $category = $line['category'] ?? BankDayCategory::NONE;
            $categories[$day][$category] ??= [0, 0, 0, 0];
            $categories[$day][$category][0] += $in;
            $categories[$day][$category][1] += $out;
            $categories[$day][$category][2] += $amount > 0 ? 1 : 0;
            $categories[$day][$category][3] += $amount < 0 ? 1 : 0;

            $month = substr($day, 0, 7);
            $key = Counterparty::key($line['counterpartyName'], $line['counterpartyIban'], $line['label']);
            $counterparties[$month][$key] ??= [Counterparty::name($line['counterpartyName'], $line['label']), 0, 0, 0, 0];
            $counterparties[$month][$key][1] += $in;
            $counterparties[$month][$key][2] += $out;
            $counterparties[$month][$key][3] += $amount > 0 ? 1 : 0;
            $counterparties[$month][$key][4] += $amount < 0 ? 1 : 0;
        }

        $final = $total;
        if (null !== $account->getBankBalance()) {
            $at = $account->getBankBalanceAt()?->format('Y-m-d');
            $after = 0;
            foreach ($lines as $line) {
                if (null !== $at && $line['bookedOn']->format('Y-m-d') > $at) {
                    $after += $line['amount'];
                }
            }
            $final = $account->getBankBalance() + $after;
        }

        $rows = ['days' => [], 'categories' => [], 'counterparties' => [], 'recurring' => []];
        $running = $final - $total;
        foreach ($days as $day => [$in, $out, $inCount, $outCount, $net, $transferIn, $transferOut, $transferInCount, $transferOutCount]) {
            $running += $net;
            $rows['days'][] = new BankDay($account, new \DateTimeImmutable($day), $in, $out, $inCount, $outCount, $running, $transferIn, $transferOut, $transferInCount, $transferOutCount);
        }
        foreach ($categories as $day => $byCategory) {
            foreach ($byCategory as $category => [$in, $out, $inCount, $outCount]) {
                $rows['categories'][] = new BankDayCategory($account, new \DateTimeImmutable($day), (string) $category, $in, $out, $inCount, $outCount);
            }
        }
        foreach ($counterparties as $month => $byKey) {
            foreach ($byKey as $key => [$name, $in, $out, $inCount, $outCount]) {
                $rows['counterparties'][] = new BankCounterpartyMonth($account, new \DateTimeImmutable($month.'-01'), (string) $key, $name, $in, $out, $inCount, $outCount);
            }
        }
        $rows['recurring'] = Recurrences::detect($account, $lines, $today);

        return $rows;
    }
}
