<?php

declare(strict_types=1);

final class SimplePdf
{
    private float $width;
    private float $height;
    private array $pages = [];
    private int $currentPage = -1;
    private array $images = [];
    private int $imageCounter = 1;
    private const HELVETICA_WIDTHS = [
        32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667, 39 => 222,
        40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278,
        48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556, 53 => 556, 54 => 556, 55 => 556,
        56 => 556, 57 => 556, 58 => 278, 59 => 278, 60 => 584, 61 => 584, 62 => 584, 63 => 556,
        64 => 1015, 65 => 667, 66 => 667, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778,
        72 => 722, 73 => 278, 74 => 500, 75 => 667, 76 => 556, 77 => 833, 78 => 722, 79 => 778,
        80 => 667, 81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944,
        88 => 667, 89 => 667, 90 => 611, 91 => 278, 92 => 278, 93 => 278, 94 => 469, 95 => 556,
        96 => 222, 97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556, 102 => 278, 103 => 556,
        104 => 556, 105 => 222, 106 => 222, 107 => 500, 108 => 222, 109 => 833, 110 => 556, 111 => 556,
        112 => 556, 113 => 556, 114 => 333, 115 => 500, 116 => 278, 117 => 556, 118 => 500, 119 => 722,
        120 => 500, 121 => 500, 122 => 500, 123 => 334, 124 => 260, 125 => 334, 126 => 584,
        176 => 400,
    ];
    private const HELVETICA_BOLD_WIDTHS = [
        32 => 278, 33 => 333, 34 => 474, 35 => 556, 36 => 556, 37 => 889, 38 => 722, 39 => 278,
        40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278,
        48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556, 53 => 556, 54 => 556, 55 => 556,
        56 => 556, 57 => 556, 58 => 333, 59 => 333, 60 => 584, 61 => 584, 62 => 584, 63 => 611,
        64 => 975, 65 => 722, 66 => 722, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778,
        72 => 722, 73 => 278, 74 => 556, 75 => 722, 76 => 611, 77 => 833, 78 => 722, 79 => 778,
        80 => 667, 81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944,
        88 => 667, 89 => 667, 90 => 611, 91 => 333, 92 => 278, 93 => 333, 94 => 584, 95 => 556,
        96 => 278, 97 => 556, 98 => 611, 99 => 556, 100 => 611, 101 => 556, 102 => 333, 103 => 611,
        104 => 611, 105 => 278, 106 => 278, 107 => 556, 108 => 278, 109 => 889, 110 => 611, 111 => 611,
        112 => 611, 113 => 611, 114 => 389, 115 => 556, 116 => 333, 117 => 611, 118 => 556, 119 => 778,
        120 => 556, 121 => 556, 122 => 500, 123 => 389, 124 => 280, 125 => 389, 126 => 584,
        176 => 400,
    ];

    public function addPage(float $width = 595.5, float $height = 842.25): void
    {
        $this->width = $width;
        $this->height = $height;
        $this->pages[] = '';
        $this->currentPage = count($this->pages) - 1;
    }

    public function line(float $x1, float $y1, float $x2, float $y2, string $color = '#222222', float $width = 1): void
    {
        [$r, $g, $b] = $this->rgb($color);
        $this->write(sprintf("%.3F w %.3F %.3F %.3F RG %.3F %.3F m %.3F %.3F l S\n", $width, $r, $g, $b, $x1, $this->pdfY($y1), $x2, $this->pdfY($y2)));
    }

    public function rect(float $x, float $y, float $w, float $h, string $fill = '#ffffff', ?string $stroke = null): void
    {
        $cmd = '';
        if ($fill !== '') {
            [$r, $g, $b] = $this->rgb($fill);
            $cmd .= sprintf("%.3F %.3F %.3F rg ", $r, $g, $b);
        }
        if ($stroke !== null) {
            [$r, $g, $b] = $this->rgb($stroke);
            $cmd .= sprintf("%.3F %.3F %.3F RG ", $r, $g, $b);
        }
        $op = $fill !== '' && $stroke !== null ? 'B' : ($stroke !== null ? 'S' : 'f');
        $this->write($cmd . sprintf("%.3F %.3F %.3F %.3F re %s\n", $x, $this->height - $y - $h, $w, $h, $op));
    }

    public function text(float $x, float $y, string $text, float $size = 10, string $font = 'regular', string $color = '#222222', ?float $boxWidth = null, string $align = 'left'): void
    {
        $drawX = $x;
        if ($boxWidth !== null) {
            $textWidth = $this->stringWidth($text, $size, $font);
            if ($align === 'right') {
                $drawX = $x + $boxWidth - $textWidth;
            } elseif ($align === 'center') {
                $drawX = $x + (($boxWidth - $textWidth) / 2);
            }
        }

        if (str_contains($text, '₲') || str_contains($text, '฿')) {
            $this->writeTextWithCurrencySymbols($drawX, $y, $text, $size, $font, $color);
            return;
        }

        $encoded = $this->encodeText($text);
        [$r, $g, $b] = $this->rgb($color);
        $fontName = $font === 'bold' ? 'F2' : 'F1';
        $baseline = $this->height - $y - $size;
        $this->write(sprintf("BT /%s %.3F Tf %.3F %.3F %.3F rg 1 0 0 1 %.3F %.3F Tm (%s) Tj ET\n", $fontName, $size, $r, $g, $b, $drawX, $baseline, $encoded));
    }

    public function multiline(float $x, float $y, float $w, string $text, float $size = 9, string $font = 'regular', string $color = '#222222', float $lineHeight = 12, int $maxLines = 10): float
    {
        $lines = $this->wrapText($text, $w, $size, $maxLines);
        foreach ($lines as $index => $line) {
            $this->text($x, $y + ($index * $lineHeight), $line, $size, $font, $color);
        }
        return $y + (count($lines) * $lineHeight);
    }

    public function wrapLines(string $text, float $width, float $size = 9): array
    {
        return $this->wrapText($text, $width, $size, PHP_INT_MAX);
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function imagePng(string $path, float $x, float $y, float $w, float $h): bool
    {
        if (!is_file($path) || !function_exists('imagecreatefrompng')) {
            return false;
        }

        $image = @imagecreatefrompng($path);
        if (!$image) {
            return false;
        }

        $srcW = imagesx($image);
        $srcH = imagesy($image);
        if ($srcW <= 0 || $srcH <= 0) {
            imagedestroy($image);
            return false;
        }

        $raw = '';
        for ($yy = 0; $yy < $srcH; $yy++) {
            for ($xx = 0; $xx < $srcW; $xx++) {
                $rgba = imagecolorat($image, $xx, $yy);
                $a = ($rgba & 0x7F000000) >> 24;
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;
                if ($a > 0) {
                    $alpha = $a / 127;
                    $r = (int) round(($r * (1 - $alpha)) + (255 * $alpha));
                    $g = (int) round(($g * (1 - $alpha)) + (255 * $alpha));
                    $b = (int) round(($b * (1 - $alpha)) + (255 * $alpha));
                }
                $raw .= chr($r) . chr($g) . chr($b);
            }
        }
        imagedestroy($image);

        $name = 'I' . $this->imageCounter++;
        $this->images[$name] = [
            'width' => $srcW,
            'height' => $srcH,
            'data' => gzcompress($raw),
        ];

        $this->write(sprintf("q %.3F 0 0 %.3F %.3F %.3F cm /%s Do Q\n", $w, $h, $x, $this->height - $y - $h, $name));
        return true;
    }

    public function output(string $path): void
    {
        $objects = [];
        $objects[1] = '';
        $objects[2] = '';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        $next = 5;
        $imageRefs = [];
        foreach ($this->images as $name => $image) {
            $imageRefs[$name] = $next;
            $stream = $image['data'];
            $objects[$next] = "<< /Type /XObject /Subtype /Image /Width {$image['width']} /Height {$image['height']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
            $next++;
        }

        $xObjects = '';
        foreach ($imageRefs as $name => $objectNumber) {
            $xObjects .= '/' . $name . ' ' . $objectNumber . ' 0 R ';
        }
        $resourceObject = $next++;
        $objects[$resourceObject] = '<< /Font << /F1 3 0 R /F2 4 0 R >>' . ($xObjects ? ' /XObject << ' . trim($xObjects) . ' >>' : '') . ' >>';

        $pageNumbers = [];
        foreach ($this->pages as $content) {
            $contentObject = $next++;
            $objects[$contentObject] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
            $pageObject = $next++;
            $objects[$pageObject] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.3F %.3F] /Resources %d 0 R /Contents %d 0 R >>', $this->width, $this->height, $resourceObject, $contentObject);
            $pageNumbers[] = $pageObject;
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = implode(' ', array_map(static fn(int $num): string => $num . ' 0 R', $pageNumbers));
        $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($pageNumbers) . ' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $maxObject = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maxObject + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxObject; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size " . ($maxObject + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";

        if (file_put_contents($path, $pdf) === false) {
            throw new RuntimeException('No se pudo escribir el PDF.');
        }
    }

    public function truncate(string $text, float $width, float $size): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if ($this->stringWidth($text, $size) <= $width) {
            return $text;
        }

        while ($text !== '' && $this->stringWidth($text . '...', $size) > $width) {
            $text = substr($text, 0, -1);
        }
        return rtrim($text) . '...';
    }

    private function wrapText(string $text, float $width, float $size, int $maxLines): array
    {
        $lines = [];
        foreach (preg_split('/\R/', trim($text)) ?: [] as $paragraph) {
            $words = preg_split('/\s+/', trim($paragraph)) ?: [];
            $line = '';
            foreach ($words as $word) {
                $candidate = $line === '' ? $word : $line . ' ' . $word;
                if ($this->stringWidth($candidate, $size) <= $width) {
                    $line = $candidate;
                    continue;
                }
                if ($line !== '') {
                    $lines[] = $line;
                }
                $line = $word;
                if (count($lines) >= $maxLines) {
                    return $this->capLastLine($lines, $width, $size);
                }
            }
            if ($line !== '') {
                $lines[] = $line;
            }
            if (count($lines) >= $maxLines) {
                return $this->capLastLine($lines, $width, $size);
            }
        }

        return $this->capLastLine($lines, $width, $size);
    }

    private function capLastLine(array $lines, float $width, float $size): array
    {
        if ($lines === []) {
            return [];
        }
        if (count($lines) > 0) {
            $lastIndex = count($lines) - 1;
            $lines[$lastIndex] = $this->truncate($lines[$lastIndex], $width, $size);
        }
        return $lines;
    }

    private function stringWidth(string $text, float $size, string $font = 'regular'): float
    {
        $encoded = $this->toWinAnsi($text);
        $widths = $font === 'bold' ? self::HELVETICA_BOLD_WIDTHS : self::HELVETICA_WIDTHS;
        $width = 0;
        $length = strlen($encoded);

        for ($i = 0; $i < $length; $i++) {
            $width += $widths[ord($encoded[$i])] ?? 556;
        }

        return $width * $size / 1000;
    }

    private function encodeText(string $text): string
    {
        $text = $this->toWinAnsi($text);
        return strtr($text, [
            '\\' => '\\\\',
            '(' => '\\(',
            ')' => '\\)',
        ]);
    }

    private function toWinAnsi(string $text): string
    {
        $text = strtr($text, [
            '₲' => 'G',
            '฿' => 'B',
        ]);
        $encoded = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        return $encoded === false ? $text : $encoded;
    }

    private function writeTextWithCurrencySymbols(float $x, float $y, string $text, float $size, string $font, string $color): void
    {
        $parts = preg_split('/(₲|฿)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $cursorX = $x;

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $isSpecial = $part === '₲' || $part === '฿';
            $visibleText = $part === '₲' ? 'G' : ($part === '฿' ? 'B' : $part);
            $encoded = $this->encodeText($visibleText);
            [$r, $g, $b] = $this->rgb($color);
            $fontName = $font === 'bold' ? 'F2' : 'F1';
            $baseline = $this->height - $y - $size;
            $this->write(sprintf("BT /%s %.3F Tf %.3F %.3F %.3F rg 1 0 0 1 %.3F %.3F Tm (%s) Tj ET\n", $fontName, $size, $r, $g, $b, $cursorX, $baseline, $encoded));

            $partWidth = $this->stringWidth($visibleText, $size, $font);
            if ($isSpecial) {
                $strokeWidth = max(0.45, $size * 0.065);
                if ($part === '₲') {
                    $this->line(
                        $cursorX + ($partWidth * 0.08),
                        $y + ($size * 0.55),
                        $cursorX + ($partWidth * 0.92),
                        $y + ($size * 0.55),
                        $color,
                        $strokeWidth
                    );
                } else {
                    $this->line(
                        $cursorX + ($partWidth * 0.48),
                        $y + ($size * 0.02),
                        $cursorX + ($partWidth * 0.48),
                        $y + ($size * 1.08),
                        $color,
                        $strokeWidth
                    );
                }
            }
            $cursorX += $partWidth;
        }
    }

    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
    }

    private function pdfY(float $y): float
    {
        return $this->height - $y;
    }

    private function write(string $content): void
    {
        if ($this->currentPage < 0) {
            $this->addPage();
        }
        $this->pages[$this->currentPage] .= $content;
    }
}

function generateProformaPdf(array $proforma, array $client, array $items, array $taxSummary, string $outputPath): void
{
    ensureStorageDirectories();

    $pdf = new SimplePdf();
    $orange = '#ff7a14';
    $lightGray = '#ececec';
    $text = '#222222';
    $itemsPerPage = 14;
    $formatType = normalizeProformaFormat((string) ($proforma['format_type'] ?? 'detallado'));

    $pages = array_chunk(array_values($items), $itemsPerPage);
    if ($pages === []) {
        $pages = [[]];
    }

    $pageCount = count($pages);
    foreach ($pages as $pageIndex => $pageItems) {
        $pdf->addPage(595.5, 842.25);
        drawProformaHeader($pdf, $proforma, $client, $orange);
        $visualRowCount = $pageIndex === $pageCount - 1
            ? max(4, count($pageItems))
            : $itemsPerPage;
        drawProformaItemsTable($pdf, $pageItems, $visualRowCount, $orange, $lightGray, $text, $formatType, $proforma);

        if ($pageIndex === $pageCount - 1) {
            $tableBottom = 188.0 + 30.0 + ($visualRowCount * 30.0);
            $needsContinuation = drawProformaBottom($pdf, $proforma, $taxSummary, $orange, $lightGray, $tableBottom);
            if ($needsContinuation) {
                drawProformaNotesContinuationPages($pdf, $proforma, $orange);
            }
        }
    }

    $pdf->output($outputPath);
}

function regenerateStoredProformaPdf(PDO $pdo, int $proformaId): string
{
    $proforma = loadProformaForDelivery($pdo, $proformaId);
    if (!$proforma) {
        throw new RuntimeException('La proforma seleccionada no existe.');
    }

    $itemsStmt = $pdo->prepare('SELECT * FROM proforma_items WHERE proforma_id = :id ORDER BY id');
    $itemsStmt->execute([':id' => $proformaId]);
    $items = $itemsStmt->fetchAll();
    $client = [
        'empresa' => (string) ($proforma['company_name_snapshot'] ?: $proforma['client_empresa'] ?? ''),
        'ruc' => (string) ($proforma['client_ruc'] ?? ''),
        'direccion' => (string) ($proforma['client_direccion'] ?? ''),
        'telefono' => (string) ($proforma['client_telefono'] ?? ''),
    ];
    $proforma['disclaimers'] = loadProformaDisclaimerSnapshots($pdo, $proformaId);

    $filename = safeBasename((string) ($proforma['pdf_path'] ?? ''));
    if ($filename === '') {
        $filename = proformaPdfFilename((string) $proforma['proforma_number']);
    }
    $outputPath = PROFORMA_STORAGE_PATH . '/' . $filename;
    generateProformaPdf(
        $proforma,
        $client,
        $items,
        buildTaxSummary($items, (float) ($proforma['discount_percent'] ?? 0)),
        $outputPath
    );

    $update = $pdo->prepare('UPDATE proformas SET pdf_path = :pdf_path WHERE id = :id');
    $update->execute([
        ':pdf_path' => storageRelativePath($outputPath),
        ':id' => $proformaId,
    ]);

    return $outputPath;
}

function drawProformaHeader(SimplePdf $pdf, array $proforma, array $client, string $orange): void
{
    drawAtexLogo($pdf, 40, 30, $orange);

    $contentRightX = 570.0;
    $headerW = 290.0;
    $headerX = $contentRightX - $headerW;
    $projectName = $pdf->truncate('Proyecto: ' . proformaProjectName($proforma), $headerW, 9);
    $pdf->text($headerX, 32, 'Proforma N° ' . $proforma['proforma_number'], 14, 'bold', '#000000', $headerW, 'right');
    $pdf->text($headerX, 51, $projectName, 9, 'bold', '#333333', $headerW, 'right');
    $pdf->text($headerX, 68, 'Emisión: ' . formatDateLong((string) $proforma['emission_date']), 10, 'regular', '#000000', $headerW, 'right');
    $pdf->text($headerX, 85, 'Vencimiento del presupuesto: ' . formatDateLong((string) $proforma['expiration_date']), 10, 'regular', '#000000', $headerW, 'right');
    $authorizationStatus = proformaAuthorizationStatus($proforma);
    if ($authorizationStatus === 'PENDING') {
        $pdf->text($headerX, 98, 'PENDIENTE DE AUTORIZACIÓN DE TIPO DE CAMBIO', 8, 'bold', $orange, $headerW, 'right');
    } elseif ($authorizationStatus === 'REJECTED') {
        $pdf->text($headerX, 98, 'RECHAZADA - NO VÁLIDA PARA ENTREGA FINAL', 8, 'bold', '#b42318', $headerW, 'right');
    }

    $pdf->line(25, 110, 570, 110, '#999999', 0.8);
    $pdf->text(25, 126, 'Empresa: ' . displayOrMarker($client['empresa'] ?? ''), 12, 'bold', '#000000');
    $pdf->text(25, 148, 'Dirección: ' . displayOrMarker($client['direccion'] ?? ''), 12, 'bold', '#000000');
    $clientRightW = 260.0;
    $clientRightX = $contentRightX - $clientRightW;
    $pdf->text($clientRightX, 126, 'RUC: ' . displayOrMarker($client['ruc'] ?? ''), 12, 'bold', '#000000', $clientRightW, 'right');
    $pdf->text($clientRightX, 148, 'Tel: ' . displayOrMarker($client['telefono'] ?? ''), 12, 'bold', '#000000', $clientRightW, 'right');
    $contactName = trim((string) ($proforma['contact_name'] ?? ''));
    if ($contactName !== '') {
        $pdf->text(25, 168, 'Contacto: ' . displayOrMarker($contactName), 9, 'bold', '#000000');
        $contactDetail = trim((string) ($proforma['contact_email'] ?? ''));
        $contactPhone = trim((string) ($proforma['contact_phone'] ?? ''));
        if ($contactPhone !== '') {
            $contactDetail .= ($contactDetail !== '' ? ' - ' : '') . $contactPhone;
        }
        $pdf->text($clientRightX, 168, displayOrMarker($contactDetail), 9, 'regular', '#000000', $clientRightW, 'right');
    }
    $pdf->line(25, 180, 570, 180, '#999999', 0.8);
}

function drawProformaItemsTable(SimplePdf $pdf, array $items, int $visualRowCount, string $orange, string $lightGray, string $text, string $formatType, array $proforma): void
{
    if ($formatType === 'generico') {
        drawProformaGenericItemsTable($pdf, $items, $visualRowCount, $orange, $lightGray, $text);
        return;
    }

    drawProformaDetailedItemsTable($pdf, $items, $visualRowCount, $orange, $lightGray, $text, $proforma);
}

function drawProformaDetailedItemsTable(SimplePdf $pdf, array $items, int $visualRowCount, string $orange, string $lightGray, string $text, array $proforma): void
{
    $tableX = 25.0;
    $tableY = 188.0;
    $tableW = 545.0;
    $headerH = 30.0;
    $rowH = 30.0;
    $columns = [75.0, 235.0, 105.0, 75.0, 55.0];
    $headers = ['Cantidad', 'Descripción', 'Precio diario', 'Cant. días', 'Total'];

    $pdf->rect($tableX, $tableY, $tableW, $headerH, $orange);
    $cursorX = $tableX;
    foreach ($headers as $i => $header) {
        $align = in_array($i, [2, 4], true) ? 'right' : ($i === 0 || $i === 3 ? 'center' : 'left');
        $pad = $align === 'left' ? 10 : 0;
        $pdf->text($cursorX + $pad, $tableY + 10, $header, 9, 'bold', '#ffffff', $columns[$i] - 10, $align);
        $cursorX += $columns[$i];
    }

    $startY = $tableY + $headerH;
    for ($index = 0; $index < $visualRowCount; $index++) {
        $rowY = $startY + ($index * $rowH);
        $rowFill = $index % 2 === 1 ? $lightGray : '#ffffff';
        $pdf->rect($tableX, $rowY, $tableW, $rowH, $rowFill);
        if ($index >= count($items)) {
            drawEmptyItemRowSeal($pdf, $tableX, $rowY, $rowH, $columns);
        }
    }

    foreach ($items as $index => $item) {
        $rowY = $startY + ($index * $rowH);
        $desc = $pdf->truncate((string) ($item['description'] ?? ''), 215, 9);
        $pdf->text($tableX, $rowY + 10, formatNumber((float) $item['quantity']), 9, 'regular', $text, $columns[0], 'center');
        $pdf->text($tableX + $columns[0] + 10, $rowY + 10, $desc, 9, 'regular', $text);
        $pdf->text($tableX + $columns[0] + $columns[1], $rowY + 10, formatProformaMoney((float) $item['unit_price'], $proforma), 9, 'bold', $text, $columns[2] - 10, 'right');
        $pdf->text($tableX + $columns[0] + $columns[1] + $columns[2], $rowY + 10, formatInteger((int) $item['rental_days']), 9, 'regular', $text, $columns[3], 'center');
        $pdf->text($tableX + $columns[0] + $columns[1] + $columns[2] + $columns[3], $rowY + 10, formatProformaMoney((float) $item['total'], $proforma), 9, 'bold', $text, $columns[4] - 8, 'right');
    }
}

function drawProformaGenericItemsTable(SimplePdf $pdf, array $items, int $visualRowCount, string $orange, string $lightGray, string $text): void
{
    $tableX = 25.0;
    $tableY = 188.0;
    $tableW = 545.0;
    $headerH = 30.0;
    $rowH = 30.0;
    $columns = [65.0, 480.0];
    $headers = ['Cantidad', 'Descripción'];

    $pdf->rect($tableX, $tableY, $tableW, $headerH, $orange);
    $cursorX = $tableX;
    foreach ($headers as $i => $header) {
        $align = $i === 0 ? 'center' : 'left';
        $pad = $align === 'left' ? 10 : 0;
        $pdf->text($cursorX + $pad, $tableY + 10, $header, 9, 'bold', '#ffffff', $columns[$i] - 10, $align);
        $cursorX += $columns[$i];
    }

    $startY = $tableY + $headerH;
    for ($index = 0; $index < $visualRowCount; $index++) {
        $rowY = $startY + ($index * $rowH);
        $rowFill = $index % 2 === 1 ? $lightGray : '#ffffff';
        $pdf->rect($tableX, $rowY, $tableW, $rowH, $rowFill);
        if ($index >= count($items)) {
            drawEmptyItemRowSeal($pdf, $tableX, $rowY, $rowH, $columns);
        }
    }

    foreach ($items as $index => $item) {
        $rowY = $startY + ($index * $rowH);
        $desc = $pdf->truncate((string) ($item['description'] ?? ''), $columns[1] - 20, 9);
        $pdf->text($tableX, $rowY + 10, formatNumber((float) $item['quantity']), 9, 'regular', $text, $columns[0], 'center');
        $pdf->text($tableX + $columns[0] + 10, $rowY + 10, $desc, 9, 'regular', $text);
    }
}

function drawEmptyItemRowSeal(SimplePdf $pdf, float $tableX, float $rowY, float $rowH, array $columns): void
{
    $cursorX = $tableX;
    foreach ($columns as $columnWidth) {
        $dashCount = max(7, (int) floor(($columnWidth - 18.0) / 4.0));
        $pdf->text($cursorX + 6, $rowY + (($rowH - 8.0) / 2), str_repeat('-', $dashCount), 8, 'regular', '#858585', $columnWidth - 12, 'center');
        $cursorX += $columnWidth;
    }
}

function drawProformaBottom(SimplePdf $pdf, array $proforma, array $taxSummary, string $orange, string $lightGray, float $tableBottom): bool
{
    $taxLabel = 'Impuestos';
    if (count($taxSummary) === 1) {
        $taxLabel = $taxSummary[0]['label'];
    } elseif (count($taxSummary) > 1) {
        $taxLabel = 'varios';
    }

    $contentY = $tableBottom + 14.0;
    drawProformaTotalsAt($pdf, $proforma, $taxLabel, $orange, $lightGray, $contentY);
    $records = buildProformaNoteRecords($pdf, $proforma, 250.0);
    $recordsHeight = proformaNoteRecordsHeight($records);
    $notesLimit = 680.0;
    $fits = $records === [] || ($contentY + $recordsHeight <= $notesLimit);
    if ($fits && $records !== []) {
        drawProformaNoteRecords($pdf, $records, 25.0, $contentY);
    }
    if ($fits) {
        drawProformaSignature($pdf, $proforma, max(690.0, $contentY + $recordsHeight + 8.0));
    }
    drawAtexFooter($pdf, $orange);
    return !$fits;
}

function drawProformaTotalsAt(SimplePdf $pdf, array $proforma, string $taxLabel, string $orange, string $lightGray, float $y): void
{
    $x = 290.0;
    $w = 280.0;
    $rowH = 24.0;
    $discountAmount = round((float) ($proforma['discount_amount'] ?? 0), 2);
    $rows = [
        ['Subtotal', formatProformaMoney((float) $proforma['subtotal'], $proforma)],
    ];
    if ($discountAmount > 0) {
        $rows[] = [
            'Descuento (' . formatNumber((float) $proforma['discount_percent']) . '%)',
            '-' . formatProformaMoney($discountAmount, $proforma),
        ];
    }
    $rows[] = [$taxLabel, formatProformaMoney((float) $proforma['tax_total'], $proforma)];
    foreach ($rows as $index => [$label, $value]) {
        $rowY = $y + ($index * $rowH);
        $pdf->rect($x, $rowY, $w, $rowH, $index % 2 === 0 ? $lightGray : '#ffffff');
        $pdf->text($x + 8, $rowY + 8, $label, 8, 'bold', '#333333');
        $pdf->text($x, $rowY + 8, $value, 8, 'bold', '#333333', $w - 10, 'right');
    }
    $totalY = $y + (count($rows) * $rowH);
    $pdf->rect($x, $totalY, $w, 36, $orange);
    $pdf->text($x + 8, $totalY + 12, 'TOTAL', 10, 'bold', '#ffffff');
    $pdf->text($x, $totalY + 10, formatProformaMoney((float) $proforma['total'], $proforma), 15, 'bold', '#ffffff', $w - 10, 'right');
}

function buildProformaNoteRecords(SimplePdf $pdf, array $proforma, float $width): array
{
    $records = [];
    $observations = proformaObservations($proforma);
    if ($observations !== '') {
        $records[] = ['Observaciones', 11.0, 'bold', '#000000', 15.0];
        foreach ($pdf->wrapLines($observations, $width, 8.5) as $line) {
            $records[] = [$line, 8.5, 'regular', '#333333', 11.0];
        }
        $records[] = ['', 8.0, 'regular', '#333333', 7.0];
    }

    $disclaimers = is_array($proforma['disclaimers'] ?? null) ? $proforma['disclaimers'] : [];
    if ($disclaimers !== []) {
        $records[] = [proformaDisclaimersHeading(), 10.0, 'bold', '#000000', 14.0];
        foreach ($disclaimers as $disclaimer) {
            $title = trim((string) ($disclaimer['title_snapshot'] ?? $disclaimer['title'] ?? ''));
            $body = trim((string) ($disclaimer['body_snapshot'] ?? $disclaimer['body'] ?? ''));
            $line = '- ' . ($title !== '' ? $title . ': ' : '') . $body;
            foreach ($pdf->wrapLines($line, $width, 7.5) as $wrapped) {
                $records[] = [$wrapped, 7.5, 'regular', '#444444', 10.0];
            }
        }
    }
    return $records;
}

function proformaNoteRecordsHeight(array $records): float
{
    return array_reduce($records, static fn(float $height, array $record): float => $height + (float) $record[4], 0.0);
}

function drawProformaNoteRecords(SimplePdf $pdf, array $records, float $x, float $y): float
{
    foreach ($records as [$text, $size, $font, $color, $lineHeight]) {
        if ($text !== '') {
            $pdf->text($x, $y, $text, $size, $font, $color);
        }
        $y += $lineHeight;
    }
    return $y;
}

function drawProformaNotesContinuationPages(SimplePdf $pdf, array $proforma, string $orange): void
{
    $records = buildProformaNoteRecords($pdf, $proforma, 545.0);
    while ($records !== []) {
        $pdf->addPage(595.5, 842.25);
        drawAtexLogo($pdf, 40, 28, $orange);
        $pdf->text(280, 34, 'Proforma N° ' . (string) $proforma['proforma_number'], 12, 'bold', '#000000', 290, 'right');
        $pdf->text(280, 56, 'Proyecto: ' . displayOrMarker($proforma['project_name'] ?? ''), 9, 'regular', '#333333', 290, 'right');
        $pdf->line(25, 105, 570, 105, '#999999', 0.8);

        $available = 545.0;
        $remainingHeight = proformaNoteRecordsHeight($records);
        if ($remainingHeight <= 445.0) {
            $available = 445.0;
        }
        $pageRecords = [];
        $used = 0.0;
        while ($records !== [] && $used + (float) $records[0][4] <= $available) {
            $record = array_shift($records);
            $pageRecords[] = $record;
            $used += (float) $record[4];
        }
        $endY = drawProformaNoteRecords($pdf, $pageRecords, 25.0, 125.0);
        if ($records === []) {
            drawProformaSignature($pdf, $proforma, max(590.0, $endY + 18.0));
        }
        drawAtexFooter($pdf, $orange);
    }
}

function drawProformaDiscountTotalsPanel(SimplePdf $pdf, array $proforma, string $taxLabel, float $discountPercent, float $discountAmount, string $orange, string $lightGray): void
{
    $totalsX = 290.0;
    $totalsW = 280.0;
    $panelY = 646.0;
    $rowH = 22.0;
    $labelX = $totalsX + 8;
    $valueW = $totalsW - 10;

    $pdf->rect($totalsX, $panelY, $totalsW, $rowH, $lightGray);
    $pdf->text($labelX, $panelY + 7, 'Subtotal', 8, 'bold', '#333333');
    $pdf->text($totalsX, $panelY + 7, formatProformaMoney((float) $proforma['subtotal'], $proforma), 8, 'bold', '#333333', $valueW, 'right');

    $discountY = $panelY + $rowH;
    $pdf->rect($totalsX, $discountY, $totalsW, $rowH, '#ffffff');
    $pdf->text($labelX, $discountY + 7, 'Descuento (' . formatNumber($discountPercent) . '%)', 8, 'bold', '#333333');
    $pdf->text($totalsX, $discountY + 7, '-' . formatProformaMoney($discountAmount, $proforma), 8, 'bold', '#333333', $valueW, 'right');

    $taxY = $discountY + $rowH;
    $pdf->rect($totalsX, $taxY, $totalsW, $rowH, $lightGray);
    $pdf->text($labelX, $taxY + 7, 'Impuestos', 8, 'bold', '#333333');
    $pdf->text($totalsX + 142, $taxY + 7, $taxLabel, 8, 'bold', '#333333');
    $pdf->text($totalsX, $taxY + 7, formatProformaMoney((float) $proforma['tax_total'], $proforma), 8, 'bold', '#333333', $valueW, 'right');

    $totalY = $taxY + $rowH;
    $pdf->rect($totalsX, $totalY, $totalsW, 34, $orange);
    $pdf->text($labelX, $totalY + 12, 'TOTAL', 10, 'bold', '#ffffff');
    $pdf->text($totalsX, $totalY + 10, formatProformaMoney((float) $proforma['total'], $proforma), 15, 'bold', '#ffffff', $valueW, 'right');
}

function drawProformaSignature(SimplePdf $pdf, array $proforma, float $topY = 690.0): void
{
    $signature = [
        trim((string) ($proforma['signer_name'] ?? '')),
        trim((string) ($proforma['signer_position'] ?? '')),
        trim((string) ($proforma['signer_email'] ?? '')),
        trim((string) ($proforma['signer_phone'] ?? '')),
        trim((string) ($proforma['signer_unit'] ?? '')),
    ];
    if (implode('', $signature) === '') {
        return;
    }

    $x = 25.0;
    $imagePath = storedSignatureAbsolutePath((string) ($proforma['signer_signature_image'] ?? ''));
    if ($imagePath !== null) {
        $pdf->imagePng($imagePath, $x, $topY, 112, 40);
    }
    $y = $topY + 49.0;
    $pdf->line($x, $y - 5, $x + 210, $y - 5, '#999999', 0.6);
    foreach ($signature as $index => $line) {
        $pdf->text($x, $y + ($index * 8), displayOrMarker($line), $index === 0 ? 8.5 : 7, $index === 0 ? 'bold' : 'regular', '#222222');
    }
}

function drawAtexLogo(SimplePdf $pdf, float $x, float $y, string $orange): void
{
    if (drawAtexPdfAsset($pdf, 'pdf_logo.png', $x - 2, $y - 6, 155, 66)) {
        return;
    }

    $logoPath = ROOT_PATH . '/assets/atex_latam_logo.png';
    if ($pdf->imagePng($logoPath, $x, $y, 160, 54.6)) {
        return;
    }

    $cell = 12.0;
    $gap = 3.0;
    for ($row = 0; $row < 3; $row++) {
        for ($col = 0; $col < 3; $col++) {
            $pdf->rect($x + ($col * ($cell + $gap)), $y + ($row * ($cell + $gap)), $cell, $cell, $orange);
        }
    }
    $pdf->text($x + 58, $y + 11, 'LATAM', 13, 'bold', $orange);
    $pdf->text($x + 46, $y + 24, 'atex', 42, 'bold', '#000000');
}

function drawAtexTotalsPanel(SimplePdf $pdf): bool
{
    return drawAtexPdfAsset($pdf, 'pdf_totals_panel.png', 290, 653, 281, 90);
}

function drawAtexFooter(SimplePdf $pdf, string $orange): void
{
    if (drawAtexPdfAsset($pdf, 'pdf_footer.png', 0, 795, 595.5, 47.25)) {
        return;
    }

    $startY = 798.0;
    $cellW = 20.0;
    $cellH = 10.0;
    $gap = 3.0;
    for ($row = 0; $row < 4; $row++) {
        for ($x = -10.0; $x < 595.5; $x += ($cellW + $gap)) {
            $pdf->rect($x, $startY + ($row * ($cellH + $gap)), $cellW, $cellH, $orange);
        }
    }
    $pdf->rect(528, 818, 45, 15, '#ffffff');
    $pdf->text(536, 820, 'atex.la', 9, 'bold', $orange);
}

function drawAtexPdfAsset(SimplePdf $pdf, string $filename, float $x, float $y, float $w, float $h): bool
{
    return $pdf->imagePng(PUBLIC_PATH . '/assets/' . $filename, $x, $y, $w, $h);
}
