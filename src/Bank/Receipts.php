<?php

namespace Base\Ledger\Bank;

use Base\Drive\Share\FileShare;
use Base\Ledger\Entity\BankLine;
use Base\Ledger\Exception\BankException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A bank line's receipt (a photo, a PDF): filed in omnibase/drive when it
 * is installed - the Partage, under ledger.receipts.folder, then the year
 * and the month of the line (« Comptabilité/Justificatifs/2026/10 »), named
 * after the line (« 2026-10-31 Monoprix -89,16.jpg ») -, else in
 * ledger.receipts.directory, never served directly. The line keeps where it
 * is; taking it off the line leaves the file where it was: the company's
 * papers are kept (the drive's retention lock refuses deleting them anyway).
 */
class Receipts
{
    public const TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'image/gif', 'application/pdf'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%ledger.receipts.folder%')] private readonly string $folder = 'Comptabilité/Justificatifs',
        #[Autowire('%ledger.receipts.audience%')] private readonly ?string $audience = 'staff',
        #[Autowire('%ledger.receipts.directory%')] private readonly string $directory = '',
        #[Autowire('%ledger.receipts.max_size%')] private readonly int $maxSize = 15728640,
        private readonly ?FileShare $share = null,
    ) {
    }

    /** Whether the receipts go to omnibase/drive. */
    public function inDrive(): bool
    {
        return null !== $this->share;
    }

    /**
     * Files $file as $line's receipt (replacing the one it had on the line, the file kept).
     *
     * @throws BankException a file that is no photo nor PDF, too large, or that could not be written
     */
    public function attach(BankLine $line, UploadedFile $file): string
    {
        if (!$file->isValid()) {
            throw new BankException(sprintf('The receipt was not received: %s', $file->getErrorMessage()));
        }
        $type = (string) ($file->getMimeType() ?: $file->getClientMimeType());
        if (!\in_array($type, self::TYPES, true)) {
            throw new BankException(sprintf('A receipt is a photo or a PDF, not "%s".', $type));
        }
        if ((int) $file->getSize() > $this->maxSize) {
            throw new BankException(sprintf('A receipt weighs %d MB at most.', intdiv($this->maxSize, 1024 * 1024)));
        }

        $extension = strtolower($file->guessExtension() ?: pathinfo($file->getClientOriginalName(), \PATHINFO_EXTENSION) ?: 'bin');
        $name = self::fileName($line, $extension);
        $stream = fopen($file->getPathname(), 'r');
        if (false === $stream) {
            throw new BankException('The receipt could not be read.');
        }
        try {
            $place = null !== $this->share ? 'drive:'.$this->toDrive($line, $name, $stream) : 'local:'.$this->toDirectory($line, $name, $stream);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        $line->setReceipt($place, $file->getClientOriginalName() ?: $name, $type);
        $this->entityManager->flush();

        return $place;
    }

    /** Takes the receipt off the line; the file stays where it was filed. */
    public function detach(BankLine $line): void
    {
        $line->setReceipt(null);
        $this->entityManager->flush();
    }

    /** Marks the line's receipt missing, or not. */
    public function markMissing(BankLine $line, bool $missing = true): void
    {
        $line->setReceiptMissing($missing && !$line->hasReceipt());
        $this->entityManager->flush();
    }

    /** @return resource|null the receipt's content, null when the line has none or its file is gone */
    public function open(BankLine $line)
    {
        $receipt = (string) $line->getReceipt();
        try {
            if (str_starts_with($receipt, 'drive:') && null !== $this->share) {
                return $this->share->stream(substr($receipt, 6));
            }
            if (str_starts_with($receipt, 'local:')) {
                $path = $this->directory.'/'.substr($receipt, 6);
                if (!str_contains(substr($receipt, 6), '..') && is_file($path)) {
                    return fopen($path, 'r') ?: null;
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /** « 2026-10-31 Monoprix -89,16.jpg »: what the accountant sees in the folder. */
    public static function fileName(BankLine $line, string $extension): string
    {
        $label = Label::clean($line->getLabel(), $line->getCounterpartyName()) ?: 'Opération';
        $label = trim((string) preg_replace('/[^\p{L}\p{N} .,&\'-]+/u', ' ', $label));
        $amount = number_format($line->getAmount() / 100, 2, ',', '');

        return sprintf('%s %s %s.%s', $line->getBookedOn()->format('Y-m-d'), mb_substr($label, 0, 80), $amount, $extension);
    }

    /** @param resource $stream */
    private function toDrive(BankLine $line, string $name, $stream): string
    {
        $folder = trim($this->folder, '/');
        $top = explode('/', $folder)[0];
        if ('' !== $top && null === $this->share->row($top) && null !== $this->audience) {
            if (!$this->share->isDirectory($top)) {
                $this->share->createDirectory($top);
            }
            $this->share->describe($top, $this->audience, null);
        }
        $directory = ltrim($folder.'/'.$line->getBookedOn()->format('Y/m'), '/');
        if (!$this->share->isDirectory($directory)) {
            $this->share->createDirectory($directory);
        }
        $path = $this->free($directory, $name, fn (string $candidate) => $this->share->isFile($candidate));
        $this->share->write($path, $stream);

        return $path;
    }

    /** @param resource $stream */
    private function toDirectory(BankLine $line, string $name, $stream): string
    {
        $relative = $line->getBookedOn()->format('Y/m');
        $directory = rtrim($this->directory, '/').'/'.$relative;
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new BankException('The receipts\' directory could not be made.');
        }
        $path = $this->free($relative, $name, fn (string $candidate) => is_file(rtrim($this->directory, '/').'/'.$candidate));
        $target = fopen(rtrim($this->directory, '/').'/'.$path, 'x');
        if (false === $target) {
            throw new BankException('The receipt could not be written.');
        }
        stream_copy_to_stream($stream, $target);
        fclose($target);

        return $path;
    }

    /** A free path in $directory for $name: itself, or « name (2).ext »... */
    private function free(string $directory, string $name, callable $exists): string
    {
        $target = ltrim($directory.'/'.$name, '/');
        for ($n = 2; $exists($target); ++$n) {
            $target = ltrim($directory.'/'.pathinfo($name, \PATHINFO_FILENAME).' ('.$n.').'.pathinfo($name, \PATHINFO_EXTENSION), '/');
        }

        return $target;
    }
}
