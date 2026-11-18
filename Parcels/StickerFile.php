<?php

namespace Okay\Modules\Sviat\Meest\Parcels;

/** Файл стікерів для завантаження: один PDF як є, кілька — одним ZIP. */
final readonly class StickerFile
{
    public function __construct(
        public string $name,
        public string $type,
        public string $bytes,
    ) {
    }

    /**
     * Масовий друк дає по PDF на кожен договір, а злити PDF без бібліотеки не можна: браузер бере з відповіді
     * форми один файл, тож кілька PDF ідуть одним ZIP (ext-zip вимагає composer.json).
     *
     * @param list<string> $pdfs
     * @param string $stamp частина імені файлу, напр. дата й час
     */
    public static function fromPdfs(array $pdfs, string $stamp): self
    {
        $stamp = (string)preg_replace('/[^\w-]/', '', $stamp);
        if (count($pdfs) === 1) {
            return new self("meest-stickers-{$stamp}.pdf", 'application/pdf', $pdfs[0]);
        }
        if ($pdfs === []) {
            throw new \InvalidArgumentException('No stickers to pack');
        }

        $path = tempnam(sys_get_temp_dir(), 'meest-stickers');
        if ($path === false) {
            throw new \RuntimeException('Cannot create a temporary file for stickers');
        }
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Cannot create a ZIP of stickers');
            }
            foreach ($pdfs as $index => $pdf) {
                if (!$zip->addFromString("meest-stickers-{$stamp}-" . ($index + 1) . '.pdf', $pdf)) {
                    throw new \RuntimeException('Cannot add a sticker to the ZIP');
                }
            }
            // close() записує архів на диск: без перевірки порожній чи обірваний ZIP пішов би менеджеру.
            if (!$zip->close()) {
                throw new \RuntimeException('Cannot write the ZIP of stickers');
            }
            $bytes = file_get_contents($path);
            if ($bytes === false || $bytes === '') {
                throw new \RuntimeException('Cannot read the ZIP of stickers');
            }
        } finally {
            @unlink($path);
        }

        return new self("meest-stickers-{$stamp}.zip", 'application/zip', $bytes);
    }
}
