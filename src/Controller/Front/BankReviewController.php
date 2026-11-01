<?php

namespace Base\Ledger\Controller\Front;

use Base\Ledger\Bank\Receipts;
use Base\Ledger\Entity\BankAccount;
use Base\Ledger\Entity\BankLine;
use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Party;
use Base\Ledger\Entity\ReconciliationRule;
use Base\Ledger\Enum\RuleSign;
use Base\Ledger\Exception\LedgerException;
use Base\Ledger\Reconciliation\Categories;
use Base\Ledger\Reconciliation\Desk;
use Base\Ledger\Reconciliation\Suggestion;
use Base\Ledger\Repository\AccountRepository;
use Base\Ledger\Repository\BankAccountRepository;
use Base\Ledger\Repository\BankLineRepository;
use Base\Ledger\Repository\BookRepository;
use Base\Ledger\Repository\PartyRepository;
use Base\Ledger\Repository\ReconciliationRuleRepository;
use Base\Ledger\Security\Voter\LedgerVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The books' day-to-day in the front office, for the team who keeps them
 * (LEDGER_MANAGE on the book; who may only view it reads): the bank lines
 * as one list, compact rows, the best suggestion beside each line to accept
 * in one click or with Enter, a category or a split otherwise, a rule made
 * from a line, receipts dropped on a row, the bank synced and what is sure
 * reconciled - each action an XHR answering the row again, with how to undo
 * it. Everything goes through Reconciliation\Desk, as the back office's
 * reconciliation page does; that page stays for monitoring.
 *
 * Its pages are in French (« /comptabilite »), as the spaces of
 * omnibase/estate are; its XHR addresses in English.
 */
#[IsGranted('ROLE_USER')]
#[Route('/comptabilite')]
class BankReviewController extends AbstractController
{
    public const PAGE = 100;
    public const STATES = ['todo', 'reconciled', 'ignored', 'missing', 'all'];
    private const TOKEN = 'ledger_review';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Desk $desk,
        private readonly Categories $categories,
        private readonly Receipts $receipts,
        private readonly BookRepository $books,
        private readonly BankAccountRepository $bankAccounts,
        private readonly BankLineRepository $bankLines,
        private readonly AccountRepository $accounts,
        private readonly PartyRepository $parties,
        private readonly ReconciliationRuleRepository $rules,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'ledger_front_review', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $book = $this->book($request);
        $filter = $this->filter($request, $book);
        [$first, $last] = $this->bankLines->span($filter['accounts']);
        $lines = $this->bankLines->review($filter['accounts'], $filter['state'], $filter['month'], $filter['q'], 0, self::PAGE + 1);

        return $this->render('@Ledger/front/review.html.twig', [
            'book' => $book,
            'books' => $this->viewableBooks(),
            'filter' => $filter,
            'bank_accounts' => $this->bankAccounts->forBook($book),
            'months' => self::months($first, $last),
            'counts' => $this->bankLines->reviewCounts($filter['accounts'], $filter['month'], $filter['q']),
            'progress' => $this->progress($filter),
            'rows' => $this->rows(\array_slice($lines, 0, self::PAGE)),
            'more' => \count($lines) > self::PAGE ? $this->moreUrl($filter, self::PAGE) : null,
            'categories' => $this->categories->of($book),
            'parties' => $this->parties->findBy(['book' => $book], ['name' => 'ASC']),
            'can_manage' => $this->isGranted(LedgerVoter::MANAGE, $book),
            'threshold' => $this->desk->threshold(),
            'token' => self::TOKEN,
            'in_drive' => $this->receipts->inDrive(),
        ]);
    }

    /** The next rows of the list (infinite scroll). */
    #[Route('/rows', name: 'ledger_front_review_rows', methods: ['GET'])]
    public function more(Request $request): JsonResponse
    {
        $book = $this->book($request);
        $filter = $this->filter($request, $book);
        $offset = max(0, (int) $request->query->get('offset'));
        $lines = $this->bankLines->review($filter['accounts'], $filter['state'], $filter['month'], $filter['q'], $offset, self::PAGE + 1);
        $html = '';
        foreach ($this->rows(\array_slice($lines, 0, self::PAGE)) as $row) {
            $html .= $this->renderRow($row, $book);
        }

        return new JsonResponse(['html' => $html, 'more' => \count($lines) > self::PAGE ? $this->moreUrl($filter, $offset + self::PAGE) : null]);
    }

    /** The open items a line may settle, for its split. */
    #[Route('/lines/{id}/candidates', name: 'ledger_front_review_candidates', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function candidates(int $id): JsonResponse
    {
        $line = $this->line($id, LedgerVoter::VIEW);

        return new JsonResponse(array_map(fn ($item) => [
            'id' => $item->getId(),
            'label' => trim(($item->getParty()?->getName() ?? $item->getAccount()->getNumber()).' · '.($item->getEntry()->getPiece() ?? $item->getEntry()->getLabel())),
            'date' => $item->getEntry()->getDate()->format('d/m/Y'),
            'amount' => abs($item->getNet()),
        ], \array_slice($this->desk->candidates($line), 0, 200)));
    }

    /** A suggestion accepted (its index among the line's), or open items / a rule / a party picked. */
    #[Route('/lines/{id}/accept', name: 'ledger_front_review_accept', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function accept(Request $request, int $id): JsonResponse
    {
        $line = $this->line($id, LedgerVoter::MANAGE, $request);
        if ($request->request->has('suggestion')) {
            $suggestion = $this->desk->suggestions($line)[(int) $request->request->get('suggestion')] ?? null;
            if (null === $suggestion) {
                return $this->failed($line, $this->translator->trans('review.flash.gone', [], 'ledger'));
            }
        } else {
            $suggestion = $this->desk->manual($line, $request->request->all('items'), (int) $request->request->get('rule'), (int) $request->request->get('party'));
        }

        return $this->act($line, fn () => $this->desk->accept($line, $suggestion), 'review.flash.accepted');
    }

    /** The whole line on one category (an account), maybe a party's, maybe taxed. */
    #[Route('/lines/{id}/categorize', name: 'ledger_front_review_categorize', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function categorize(Request $request, int $id): JsonResponse
    {
        $line = $this->line($id, LedgerVoter::MANAGE, $request);
        $party = $this->party($request, $line->getBook());
        // « number »: the category's account number (« account » is the list's bank account filter).
        $account = (string) $request->request->get('number');
        $tax = (string) $request->request->get('tax') ?: null;

        $response = $this->act($line, fn () => $this->desk->categorize($line, $account, $party, $tax), 'review.flash.categorized');
        $data = json_decode((string) $response->getContent(), true);
        if (!empty($data['ok'])) {
            // Offered at once: « Toujours classer “Monoprix” ainsi ».
            $data['rule'] = ['label' => $this->desk->label($line), 'account' => $account, 'party' => $party?->getId(), 'tax' => $tax, 'url' => $this->generateUrl('ledger_front_review_rule', ['id' => $line->getId()])];
            $response->setData($data);
        }

        return $response;
    }

    /** The line spread over several accounts or open items. */
    #[Route('/lines/{id}/split', name: 'ledger_front_review_split', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function split(Request $request, int $id): JsonResponse
    {
        $line = $this->line($id, LedgerVoter::MANAGE, $request);
        $parts = [];
        foreach ($request->request->all('parts') as $part) {
            if (!\is_array($part)) {
                continue;
            }
            $parts[] = [
                'amount' => self::minor((string) ($part['amount'] ?? '')),
                'account' => $part['account'] ?? null,
                'items' => array_filter(explode(',', (string) ($part['items'] ?? ''))),
                'party' => $part['party'] ?? null,
                'tax' => $part['tax'] ?? null,
                'label' => $part['label'] ?? null,
            ];
        }

        return $this->act($line, fn () => $this->desk->split($line, $parts), 'review.flash.split');
    }

    #[Route('/lines/{id}/ignore', name: 'ledger_front_review_ignore', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ignore(Request $request, int $id): JsonResponse
    {
        $line = $this->line($id, LedgerVoter::MANAGE, $request);

        return $this->act($line, fn () => $this->desk->ignore($line), 'review.flash.ignored');
    }

    #[Route('/lines/{id}/undo', name: 'ledger_front_review_undo', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function undo(Request $request, int $id): JsonResponse
    {
        $line = $this->line($id, LedgerVoter::MANAGE, $request);

        return $this->act($line, fn () => $this->desk->undo($line), 'review.flash.undone', false);
    }

    /** « Justificatif manquant », or not any more. */
    #[Route('/lines/{id}/missing', name: 'ledger_front_review_missing', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function missing(Request $request, int $id): JsonResponse
    {
        $line = $this->line($id, LedgerVoter::MANAGE, $request);
        $missing = $request->request->has('missing') ? $request->request->getBoolean('missing') : !$line->isReceiptMissing();
        $this->receipts->markMissing($line, $missing);

        return $this->answer($line, $this->translator->trans($line->isReceiptMissing() ? 'review.flash.missing' : 'review.flash.not_missing', [], 'ledger'));
    }

    /** A receipt (a photo, a PDF) filed for the line: dropped on its row or chosen. */
    #[Route('/lines/{id}/receipt', name: 'ledger_front_review_receipt', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function receipt(Request $request, int $id): JsonResponse
    {
        $line = $this->line($id, LedgerVoter::MANAGE, $request);
        $file = $request->files->get('receipt');
        if (null === $file) {
            return $this->failed($line, $this->translator->trans('review.flash.no_file', [], 'ledger'));
        }
        try {
            $this->receipts->attach($line, $file);
        } catch (LedgerException $e) {
            return $this->failed($line, $e->getMessage());
        }

        return $this->answer($line, $this->translator->trans('review.flash.receipt', [], 'ledger'), ['url' => $this->generateUrl('ledger_front_review_receipt_remove', ['id' => $line->getId()])]);
    }

    /** The receipt taken off the line (the file stays where it was filed). */
    #[Route('/lines/{id}/receipt/remove', name: 'ledger_front_review_receipt_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function removeReceipt(Request $request, int $id): JsonResponse
    {
        $line = $this->line($id, LedgerVoter::MANAGE, $request);
        $this->receipts->detach($line);

        return $this->answer($line, $this->translator->trans('review.flash.receipt_removed', [], 'ledger'));
    }

    #[Route('/lines/{id}/receipt', name: 'ledger_front_review_receipt_file', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function receiptFile(int $id): Response
    {
        $line = $this->line($id, LedgerVoter::VIEW);
        $stream = $line->hasReceipt() ? $this->receipts->open($line) : null;
        if (null === $stream) {
            throw $this->createNotFoundException();
        }
        $response = new StreamedResponse(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        });
        $response->headers->set('Content-Type', $line->getReceiptType() ?? 'application/octet-stream');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition('inline', 'justificatif.'.(pathinfo((string) $line->getReceiptName(), \PATHINFO_EXTENSION) ?: 'bin'), (string) preg_replace('/[^\x20-\x7e]/', '_', (string) $line->getReceiptName())));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setPrivate();

        return $response;
    }

    /** « Toujours classer “Monoprix” ainsi »: a rule from the line, applied to the other lines it matches. */
    #[Route('/lines/{id}/rule', name: 'ledger_front_review_rule', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function rule(Request $request, int $id): JsonResponse
    {
        $line = $this->line($id, LedgerVoter::MANAGE, $request);
        $book = $line->getBook();
        try {
            ['rule' => $rule, 'applied' => $applied] = $this->desk->ruleFrom($line, (string) $request->request->get('number'), $this->party($request, $book), (string) $request->request->get('tax') ?: null, (string) $request->request->get('pattern') ?: null);
        } catch (LedgerException $e) {
            return $this->failed($line, $e->getMessage());
        }

        return new JsonResponse([
            'ok' => true,
            'rows' => $this->rowsOf($applied, $book),
            'message' => $this->translator->trans('review.flash.rule', ['name' => $rule->getName(), 'count' => \count($applied)], 'ledger'),
            'undo' => ['url' => $this->generateUrl('ledger_front_review_rule_undo', ['id' => $rule->getId()]), 'data' => ['lines' => implode(',', array_map(fn (BankLine $l) => $l->getId(), $applied))]],
        ] + $this->totals($request, $book));
    }

    /** A rule just made, undone: the lines it placed put back, the rule removed. */
    #[Route('/rules/{id}/undo', name: 'ledger_front_review_rule_undo', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function undoRule(Request $request, int $id): JsonResponse
    {
        $rule = $this->entityManager->find(ReconciliationRule::class, $id) ?? throw $this->createNotFoundException();
        $this->deny(LedgerVoter::MANAGE, $rule);
        $this->assertToken($request);
        $book = $rule->getBook();
        $ids = self::ids((string) $request->request->get('lines'));
        $undone = $this->desk->undoRule($rule, $ids);

        return new JsonResponse([
            'ok' => true,
            'rows' => $this->rowsOf($this->found($ids), $book),
            'message' => $this->translator->trans('review.flash.rule_undone', ['count' => $undone], 'ledger'),
        ] + $this->totals($request, $book));
    }

    /** What ledger:bank:sync does, for the accounts shown. */
    #[Route('/sync', name: 'ledger_front_review_sync', methods: ['POST'])]
    public function sync(Request $request): JsonResponse
    {
        $book = $this->book($request, LedgerVoter::MANAGE);
        $this->assertToken($request);
        $filter = $this->filter($request, $book);
        $report = $this->desk->sync($filter['accounts']);
        $message = $this->translator->trans('review.flash.synced', ['count' => \count($report['added'])], 'ledger');
        foreach ($report['failed'] as $account => $error) {
            $message .= ' '.$this->translator->trans('review.flash.sync_failed', ['account' => $account, 'error' => $error], 'ledger');
        }

        return new JsonResponse([
            'ok' => [] === $report['failed'],
            'reload' => [] !== $report['added'],
            'message' => $message,
            'undo' => [] === $report['added'] ? null : ['url' => $this->generateUrl('ledger_front_review_sync_undo', ['book' => $book->getId()]), 'data' => ['lines' => implode(',', $report['added']), 'synced' => json_encode($report['synced'])]],
        ] + $this->totals($request, $book));
    }

    #[Route('/sync/undo', name: 'ledger_front_review_sync_undo', methods: ['POST'])]
    public function undoSync(Request $request): JsonResponse
    {
        $book = $this->book($request, LedgerVoter::MANAGE);
        $this->assertToken($request);
        $synced = json_decode((string) $request->request->get('synced'), true);
        $removed = $this->desk->undoSync($book, self::ids((string) $request->request->get('lines')), \is_array($synced) ? $synced : []);

        return new JsonResponse(['ok' => true, 'reload' => true, 'message' => $this->translator->trans('review.flash.sync_undone', ['count' => $removed], 'ledger')]);
    }

    /** « Rapprocher ce qui est sûr »: the suggestions above the threshold, for the accounts shown. */
    #[Route('/auto', name: 'ledger_front_review_auto', methods: ['POST'])]
    public function auto(Request $request): JsonResponse
    {
        $book = $this->book($request, LedgerVoter::MANAGE);
        $this->assertToken($request);
        $applied = $this->desk->autoReconcile($this->filter($request, $book)['accounts']);

        return new JsonResponse([
            'ok' => true,
            'rows' => $this->rowsOf($applied, $book),
            'message' => $this->translator->trans('review.flash.auto', ['count' => \count($applied)], 'ledger'),
            'undo' => [] === $applied ? null : ['url' => $this->generateUrl('ledger_front_review_auto_undo', ['book' => $book->getId()]), 'data' => ['lines' => implode(',', array_map(fn (BankLine $l) => $l->getId(), $applied))]],
        ] + $this->totals($request, $book));
    }

    #[Route('/auto/undo', name: 'ledger_front_review_auto_undo', methods: ['POST'])]
    public function undoAuto(Request $request): JsonResponse
    {
        $book = $this->book($request, LedgerVoter::MANAGE);
        $this->assertToken($request);
        $ids = self::ids((string) $request->request->get('lines'));
        $undone = $this->desk->undoLines($book, $ids);

        return new JsonResponse([
            'ok' => true,
            'rows' => $this->rowsOf($this->found($ids), $book),
            'message' => $this->translator->trans('review.flash.auto_undone', ['count' => $undone], 'ledger'),
        ] + $this->totals($request, $book));
    }

    /** The book's rules, listed and editable. */
    #[Route('/regles', name: 'ledger_front_rules', methods: ['GET'])]
    public function rules(Request $request): Response
    {
        $book = $this->book($request);

        return $this->render('@Ledger/front/rules.html.twig', [
            'book' => $book,
            'books' => $this->viewableBooks(),
            'rules' => $this->rules->findBy(['book' => $book], ['priority' => 'DESC', 'name' => 'ASC']),
            'categories' => $this->categories->of($book),
            'parties' => $this->parties->findBy(['book' => $book], ['name' => 'ASC']),
            'can_manage' => $this->isGranted(LedgerVoter::MANAGE, $book),
            'token' => self::TOKEN,
        ]);
    }

    #[Route('/rules/{id}', name: 'ledger_front_rule_edit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function editRule(Request $request, int $id): Response
    {
        $rule = $this->entityManager->find(ReconciliationRule::class, $id) ?? throw $this->createNotFoundException();
        $this->deny(LedgerVoter::MANAGE, $rule);
        $this->assertToken($request);
        $book = $rule->getBook();

        if ($request->request->getBoolean('delete')) {
            $this->entityManager->remove($rule);
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('review.rules.deleted', ['name' => $rule->getName()], 'ledger'));

            return $this->redirectToRoute('ledger_front_rules', ['book' => $book->getId()]);
        }

        $account = $this->accounts->findOneByNumber($book, (string) $request->request->get('account'));
        if (null !== $account && Categories::offered($account)) {
            $rule->setAccount($account);
        }
        $rule->setName(trim((string) $request->request->get('name')) ?: $rule->getName());
        $rule->setPattern(trim((string) $request->request->get('pattern')) ?: $rule->getPattern());
        $rule->setSign(RuleSign::tryFrom((string) $request->request->get('sign')) ?? $rule->getSign());
        $rule->setParty($this->party($request, $book));
        $rule->setEnabled($request->request->getBoolean('enabled'));
        $rule->setPriority((int) $request->request->get('priority'));
        $tax = trim((string) $request->request->get('tax'));
        $split = $rule->getSplit();
        if (null === $split || (1 === \count($split) && !empty($split[0]['rest']))) {
            // A rule of one account: its tax code, if any, is its only part's.
            $rule->setSplit('' === $tax ? null : [['account' => $rule->getAccount()->getNumber(), 'rest' => true, 'tax' => strtoupper($tax)]]);
        }
        $this->entityManager->flush();
        $this->addFlash('success', $this->translator->trans('review.rules.saved', ['name' => $rule->getName()], 'ledger'));

        return $this->redirectToRoute('ledger_front_rules', ['book' => $book->getId(), '_fragment' => 'rule-'.$rule->getId()]);
    }

    /**
     * Runs an action on a line and answers with the line's row again, how to undo it, and the counters.
     *
     * @param callable(): mixed $action
     */
    private function act(BankLine $line, callable $action, string $message, bool $undoable = true): JsonResponse
    {
        try {
            $action();
        } catch (LedgerException $e) {
            return $this->failed($line, $e->getMessage());
        }

        return $this->answer($line, $this->translator->trans($message, ['label' => $this->desk->label($line) ?: ($line->getLabel() ?: $this->translator->trans('review.no_label', [], 'ledger'))], 'ledger'), $undoable ? ['url' => $this->generateUrl('ledger_front_review_undo', ['id' => $line->getId()])] : null);
    }

    /** @param array{url: string, data?: array}|null $undo */
    private function answer(BankLine $line, string $message, ?array $undo = null): JsonResponse
    {
        $request = $this->container->get('request_stack')->getCurrentRequest();

        return new JsonResponse([
            'ok' => true,
            'id' => $line->getId(),
            'row' => $this->renderRow($this->rows([$line])[0], $line->getBook()),
            'message' => $message,
            'undo' => $undo,
        ] + $this->totals($request, $line->getBook()));
    }

    private function failed(BankLine $line, string $message): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'id' => $line->getId(), 'message' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** The counters and the month's progress, as the page's filter (sent along) says. */
    private function totals(?Request $request, Book $book): array
    {
        if (null === $request) {
            return [];
        }
        $filter = $this->filter($request, $book);

        $progress = $this->progress($filter);

        return [
            'counts' => $this->bankLines->reviewCounts($filter['accounts'], $filter['month'], $filter['q']),
            'progress' => $progress + ['text' => $this->translator->trans('review.progress', $progress, 'ledger')],
        ];
    }

    /** @return array{done: int, total: int} the lines handled (reconciled or ignored) among the month's - or all - lines */
    private function progress(array $filter): array
    {
        $counts = $this->bankLines->reviewCounts($filter['accounts'], $filter['month']);

        return ['done' => $counts['reconciled'] + $counts['ignored'], 'total' => $counts['all']];
    }

    /**
     * @param list<BankLine> $lines
     *
     * @return list<array{line: BankLine, label: string, suggestions: list<Suggestion>}>
     */
    private function rows(array $lines): array
    {
        return array_map(fn (BankLine $line) => ['line' => $line, 'label' => $this->desk->label($line), 'suggestions' => $this->desk->suggestions($line, 4)], $lines);
    }

    /** @param list<BankLine> $lines @return array<int, string> id => row */
    private function rowsOf(array $lines, Book $book): array
    {
        $rows = [];
        foreach ($this->rows($lines) as $row) {
            $rows[$row['line']->getId()] = $this->renderRow($row, $book);
        }

        return $rows;
    }

    private function renderRow(array $row, Book $book): string
    {
        return $this->renderView('@Ledger/front/_row.html.twig', [
            'row' => $row,
            'book' => $book,
            'can_manage' => $this->isGranted(LedgerVoter::MANAGE, $book),
            'threshold' => $this->desk->threshold(),
        ]);
    }

    /** @return array{book: int, state: string, account: ?int, month: ?string, q: ?string, accounts: list<BankAccount>} */
    private function filter(Request $request, Book $book): array
    {
        $input = 'POST' === $request->getMethod() ? $request->request : $request->query;
        $state = (string) $input->get('state', 'todo');
        $state = \in_array($state, self::STATES, true) ? $state : 'todo';
        $accounts = $this->bankAccounts->forBook($book);
        $account = (int) $input->get('account');
        if ($account > 0) {
            $accounts = array_values(array_filter($accounts, fn (BankAccount $a) => $a->getId() === $account));
        }
        $month = (string) $input->get('month');

        return [
            'book' => $book->getId(),
            'state' => $state,
            'account' => $account > 0 ? $account : null,
            'month' => preg_match('/^\d{4}-\d{2}$/', $month) ? $month : null,
            'q' => trim((string) $input->get('q')) ?: null,
            'accounts' => $accounts,
        ];
    }

    private function moreUrl(array $filter, int $offset): string
    {
        return $this->generateUrl('ledger_front_review_rows', array_filter(['book' => $filter['book'], 'state' => $filter['state'], 'account' => $filter['account'], 'month' => $filter['month'], 'q' => $filter['q'], 'offset' => $offset]));
    }

    /** The book asked (?book=), else the first the user may see; a book they may not see is not found. */
    private function book(Request $request, string $attribute = LedgerVoter::VIEW): Book
    {
        $id = (int) ('POST' === $request->getMethod() ? ($request->request->get('book') ?? $request->query->get('book')) : $request->query->get('book'));
        if ($id > 0) {
            $book = $this->books->find($id) ?? throw $this->createNotFoundException();
            $this->deny($attribute, $book);

            return $book;
        }
        foreach ($this->viewableBooks() as $book) {
            if ($this->isGranted($attribute, $book)) {
                return $book;
            }
        }

        throw $this->createNotFoundException('No book for this user.');
    }

    /**
     * Who may not even read the book finds nothing there (404: what exists is not told);
     * who reads it but may not change it is refused (403).
     */
    private function deny(string $attribute, object $subject): void
    {
        if (!$this->isGranted(LedgerVoter::VIEW, $subject)) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted($attribute, $subject);
    }

    /** @return list<Book> */
    private function viewableBooks(): array
    {
        return array_values(array_filter($this->books->findBy([], ['id' => 'ASC']), fn (Book $book) => $this->isGranted(LedgerVoter::VIEW, $book)));
    }

    private function line(int $id, string $attribute, ?Request $request = null): BankLine
    {
        $line = $this->entityManager->find(BankLine::class, $id) ?? throw $this->createNotFoundException();
        $this->deny($attribute, $line);
        if (null !== $request) {
            $this->assertToken($request);
        }

        return $line;
    }

    private function party(Request $request, Book $book): ?Party
    {
        $id = (int) $request->request->get('party');
        if ($id <= 0) {
            return null;
        }
        $party = $this->entityManager->find(Party::class, $id);
        if (null === $party || $party->getBook() !== $book) {
            throw $this->createAccessDeniedException();
        }

        return $party;
    }

    private function assertToken(Request $request): void
    {
        $token = (string) ($request->headers->get('X-CSRF-Token') ?: $request->request->get('_token'));
        if (!$this->isCsrfTokenValid(self::TOKEN, $token)) {
            throw $this->createAccessDeniedException('Invalid token.');
        }
    }

    /** @return list<BankLine> */
    private function found(array $ids): array
    {
        return array_values(array_filter(array_map(fn (int $id) => $this->entityManager->find(BankLine::class, $id), $ids)));
    }

    /** @return list<int> */
    private static function ids(string $list): array
    {
        return array_values(array_filter(array_map('intval', explode(',', $list))));
    }

    /** « 89,16 » or « 89.16 » in minor units. */
    private static function minor(string $amount): int
    {
        $amount = str_replace([' ', "\u{a0}", "\u{202f}", '€'], '', $amount);
        if (!preg_match('/^(\d+)(?:[.,](\d{1,2}))?$/', $amount, $m)) {
            return 0;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    /** @return list<string> "2026-10"..., newest first */
    private static function months(?\DateTimeImmutable $first, ?\DateTimeImmutable $last): array
    {
        if (null === $first || null === $last) {
            return [];
        }
        $months = [];
        for ($month = $last->modify('first day of this month'); $month >= $first->modify('first day of this month') && \count($months) < 60; $month = $month->modify('-1 month')) {
            $months[] = $month->format('Y-m');
        }

        return $months;
    }
}
