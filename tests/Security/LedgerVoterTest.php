<?php

namespace Tests\Base\Ledger\Security;

use Base\Ledger\Entity\Book;
use Base\Ledger\Entity\Journal;
use Base\Ledger\Security\BookAccessInterface;
use Base\Ledger\Security\Voter\LedgerVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

class LedgerVoterTest extends TestCase
{
    private function vote(bool $admin, mixed $subject, string $attribute, iterable $access = []): int
    {
        $decisions = $this->createStub(AccessDecisionManagerInterface::class);
        $decisions->method('decide')->willReturn($admin);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(new InMemoryUser('associe@example.com', null));

        return (new LedgerVoter($decisions, $access))->vote($token, $subject, [$attribute]);
    }

    public function testTheAdminRoleViewsAndManagesEverything(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(true, new Book('SCI'), LedgerVoter::MANAGE));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(true, null, LedgerVoter::VIEW));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote(true, null, 'ROLE_ADMIN'));
    }

    public function testTheApplicationGrantsABookThroughBookAccess(): void
    {
        $mine = new Book('SCI Exemple');
        $access = new class($mine) implements BookAccessInterface {
            public function __construct(private readonly Book $book) {}
            public function canView(UserInterface $user, Book $book): bool { return $book === $this->book; }
            public function canManage(UserInterface $user, Book $book): bool { return false; }
        };

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(false, $mine, LedgerVoter::VIEW, [$access]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(false, new Journal($mine, 'BQ', 'Banque'), LedgerVoter::VIEW, [$access]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(false, $mine, LedgerVoter::MANAGE, [$access]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(false, new Book('Autre SCI'), LedgerVoter::VIEW, [$access]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(false, null, LedgerVoter::VIEW, [$access]));
    }
}
