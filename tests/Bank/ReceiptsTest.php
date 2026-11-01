<?php

namespace Tests\Base\Ledger\Bank;

use Base\Ledger\Bank\Receipts;
use Base\Ledger\Exception\BankException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Tests\Base\Ledger\InMemoryLedger;

/**
 * A receipt filed for a bank line, without omnibase/drive: in the
 * receipts' directory under the line's year and month, named after the
 * line, read back; taken off the line, the file stays; a file that is no
 * photo nor PDF refused. (With the drive, the same goes to the Partage:
 * meyers' functional test.)
 */
class ReceiptsTest extends InMemoryLedger
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/ledger-receipts-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->directory);
        }
    }

    public function testAReceiptIsFiledForItsLineAndReadBack(): void
    {
        $line = $this->bankLine(-8916, 'CB DEBIT DIFFERE MONOPRIX', date: '2026-10-31');
        $line->setReceiptMissing(true);
        $receipts = $this->receipts();

        $place = $receipts->attach($line, $this->upload('%PDF-1.4 ticket', 'ticket.pdf'));

        self::assertSame('local:2026/10/2026-10-31 Monoprix -89,16.pdf', $place);
        self::assertTrue($line->hasReceipt());
        self::assertSame('ticket.pdf', $line->getReceiptName());
        self::assertSame('application/pdf', $line->getReceiptType());
        self::assertFalse($line->isReceiptMissing(), 'filed: no longer missing');
        self::assertFalse($line->isReceiptImage());
        $stream = $receipts->open($line);
        self::assertSame('%PDF-1.4 ticket', stream_get_contents($stream));

        $second = $this->bankLine(-8916, 'CB DEBIT DIFFERE MONOPRIX', date: '2026-10-31');
        self::assertSame('local:2026/10/2026-10-31 Monoprix -89,16 (2).pdf', $receipts->attach($second, $this->upload('%PDF-1.4 autre', 'b.pdf')), 'beside the first, never over it');

        $receipts->detach($line);
        self::assertFalse($line->hasReceipt());
        self::assertFileExists($this->directory.'/2026/10/2026-10-31 Monoprix -89,16.pdf', 'the file kept');
    }

    public function testOnlyAPhotoOrAPdfIsTaken(): void
    {
        $line = $this->bankLine(-1000, 'CB X');

        $this->expectException(BankException::class);
        $this->receipts()->attach($line, $this->upload('<?php echo 1;', 'script.php'));
    }

    public function testAMissingReceiptIsMarkedUnlessOneIsThere(): void
    {
        $line = $this->bankLine(-1000, 'CB X');
        $receipts = $this->receipts();

        $receipts->markMissing($line);
        self::assertTrue($line->isReceiptMissing());
        $receipts->markMissing($line, false);
        self::assertFalse($line->isReceiptMissing());
    }

    private function receipts(): Receipts
    {
        return new Receipts($this->entityManager(), 'Comptabilité/Justificatifs', 'staff', $this->directory);
    }

    private function upload(string $content, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'receipt');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }
}
