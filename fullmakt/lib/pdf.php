<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/shield.php';
jeab_deny_if_direct(__FILE__);

require_once FULLMAKT_ROOT . '/vendor/fpdf/fpdf.php';

final class FullmaktPdf extends FPDF
{
    private array $doc;
    private string $anlaggningsId;
    private string $datumOrt;
    private string $signaturePng;

    public function __construct(array $doc, string $anlaggningsId, string $datumOrt, string $signaturePng)
    {
        parent::__construct('P', 'mm', 'A4');
        $this->doc = $doc;
        $this->anlaggningsId = $anlaggningsId;
        $this->datumOrt = $datumOrt;
        $this->signaturePng = $signaturePng;
        $this->SetAutoPageBreak(false);
        $this->SetMargins(14, 10, 14);
        $this->SetCompression(true);
    }

    public function render(): string
    {
        $this->AddPage();
        $this->drawDocument();
        return $this->Output('S');
    }

    private function t(string $s): string
    {
        $out = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s);
        return $out === false ? $s : $out;
    }

    private function drawDocument(): void
    {
        $x = 14.0;
        $w = 182.0;
        $y = 10.0;

        $this->SetTextColor(255, 95, 0);
        $this->SetFont('Helvetica', 'B', 22);
        $this->SetXY($x, $y);
        $this->Cell(60, 9, $this->t('ELLEVIO'), 0, 0, 'L');
        $this->SetTextColor(0, 0, 0);

        $y = 22.0;
        $this->SetFont('Helvetica', 'B', 13);
        $this->SetXY($x, $y);
        $this->Cell($w, 6, $this->t('Fullmakt avseende anmälan av produktionsanläggning'));

        $y = 29.0;
        $this->SetFont('Helvetica', '', 8.2);
        $this->SetXY($x, $y);
        $this->MultiCell($w, 3.7, $this->t(
            'Fullmakten gäller enbart vem som har rätt att göra anmälan av produktionsanläggning till Ellevio. Fullmakten är avsedd att användas av anläggningsinnehavare som är'
        ));

        $y = 38.5;
        $typ = (string) ($this->doc['typ'] ?? 'privatperson');
        $this->SetFont('Helvetica', '', 9);
        $this->checkbox($x, $y, 'Privatperson', $typ === 'privatperson');
        $this->checkbox($x + 42, $y, 'Enskild firma', $typ === 'enskild');
        $this->checkbox($x + 86, $y, 'Företag med organisationsnummer', $typ === 'foretag');

        $y = 46.5;
        $this->sectionBar($x, $y, $w, 'Anläggningsinformation – Anläggnings-id på befintlig elanläggning*');
        $y = 52.0;
        $this->SetFont('Helvetica', '', 7.5);
        $this->SetXY($x, $y);
        $this->Cell($w, 3.5, $this->t('(finns ingen befintlig elanläggning lämna Anläggnings-id tomt)'));

        $y = 56.5;
        $this->SetFont('Helvetica', '', 8);
        $this->SetXY($x, $y);
        $this->Cell(32, 8, $this->t('Anläggnings-id'));
        $this->drawAidBoxes($x + 34, $y, 148, $this->anlaggningsId);

        $y = 67.0;
        $this->fieldBox($x, $y, $w, 11, 'Anläggningsadress', (string) ($this->doc['anlaggningsadress'] ?? ''));

        $y = 79.0;
        $this->SetFont('Helvetica', '', 7.5);
        $this->SetXY($x, $y);
        $this->Cell($w, 3.5, $this->t('*Anläggnings-id hittar du vid elmätaren och på elfakturan.'));

        $y = 85.0;
        $this->sectionBar($x, $y, $w, 'Fullmaktshavare Installatör');
        $inst = FULLMAKT_INSTALLER;
        $y = 90.5;
        $left = 118.0;
        $right = 64.0;
        $row = 11.2;
        $this->fieldBox($x, $y, $left, $row, 'Installationsföretag', $inst['foretag']);
        $this->fieldBox($x + $left, $y, $right, $row, 'Organisationsnummer', $inst['orgnr']);
        $y += $row;
        $this->fieldBox($x, $y, $left, $row, 'För- och efternamn/Installatör', $inst['namn']);
        $this->fieldBox($x + $left, $y, $right, $row, 'Telefonnummer', $inst['telefon']);
        $y += $row;
        $this->fieldBox($x, $y, $left, $row, 'Adress', $inst['adress']);
        $this->fieldBox($x + $left, $y, $right, $row, 'E-postadress', $inst['epost']);
        $y += $row;
        $this->fieldBox($x, $y, 55, $row, 'Postnummer', $inst['postnummer']);
        $this->fieldBox($x + 55, $y, $w - 55, $row, 'Ort', $inst['ort']);

        $y += $row + 4.5;
        $this->sectionBar($x, $y, $w, 'Fullmaktsgivare Anläggningsinnehavare');
        $y += 5.5;
        $this->fieldBox($x, $y, $left, $row, 'För- och efternamn/Firmatecknare', (string) ($this->doc['namn'] ?? ''));
        $this->fieldBox($x + $left, $y, $right, $row, 'Person-/organisationsnummer', (string) ($this->doc['personnummer'] ?? ''));
        $y += $row;
        $this->fieldBox($x, $y, $left, $row, 'Företagsnamn:', (string) ($this->doc['foretagsnamn'] ?? ''));
        $this->fieldBox($x + $left, $y, $right, $row, 'Telefonnummer', (string) ($this->doc['telefon'] ?? ''));
        $y += $row;
        $this->fieldBox($x, $y, $left, $row, 'Folkbokförings-/sätesadress', (string) ($this->doc['adress'] ?? ''));
        $this->fieldBox($x + $left, $y, $right, $row, 'E-postadress', (string) ($this->doc['epost'] ?? ''));
        $y += $row;
        $this->fieldBox($x, $y, 55, $row, 'Postnummer', (string) ($this->doc['postnummer'] ?? ''));
        $this->fieldBox($x + 55, $y, $w - 55, $row, 'Ort', (string) ($this->doc['ort'] ?? ''));

        $y += $row + 5.0;
        $this->SetFont('Helvetica', '', 8.2);
        $this->SetXY($x, $y);
        $this->MultiCell($w, 3.8, $this->t(
            'Härmed ger jag som är anläggningsinnehavare fullmaktshavaren rätt att för min räkning anmäla produktion på ovan angiven anläggning till Ellevio. Jag har gett fullmaktshavaren i uppdrag att svara för anläggningens regelefterlevnad och installation.'
        ));

        $y = $this->GetY() + 4.0;
        $this->sectionBar($x, $y, $w, 'Fullmaktsgivarens underskrift');
        $y += 5.5;
        $this->fieldBox($x, $y, $w, 12, 'Datum och ort', $this->datumOrt);

        $y += 12.0;
        $sigW = 112.0;
        $nameW = $w - $sigW;
        $sigH = 32.0;
        $this->Rect($x, $y, $sigW, $sigH);
        $this->SetFont('Helvetica', '', 7);
        $this->SetXY($x + 1.4, $y + 1.0);
        $this->Cell($sigW - 2.8, 3.2, $this->t('Underskrift'));
        $this->drawSignature($x + 4, $y + 5.5, $sigW - 8, $sigH - 8);

        $this->Rect($x + $sigW, $y, $nameW, $sigH);
        $this->SetFont('Helvetica', '', 7);
        $this->SetXY($x + $sigW + 1.4, $y + 1.0);
        $this->Cell($nameW - 2.8, 3.2, $this->t('Namnförtydligande'));
        $this->SetFont('Helvetica', '', 10);
        $this->SetXY($x + $sigW + 1.4, $y + 12);
        $this->MultiCell($nameW - 2.8, 5, $this->t((string) ($this->doc['namn'] ?? '')));

        $this->SetFont('Helvetica', '', 8);
        $this->SetXY($x, 272);
        $this->Cell($w, 4, $this->t('Ellevio AB (publ)'));
        $this->SetXY($x, 276);
        $this->Cell($w, 4, $this->t('Säte Stockholm. Org-nr 556037-7326'));
        $this->SetXY($x, 280);
        $this->Cell($w, 4, $this->t('Telefon 0771-53 53 00'));
        $this->SetXY($x, 284);
        $this->Cell($w, 4, $this->t('ellevio.se'));
    }

    private function sectionBar(float $x, float $y, float $w, string $title): void
    {
        $this->SetFont('Helvetica', 'B', 9.5);
        $this->SetXY($x, $y);
        $this->Cell($w, 5, $this->t($title));
        $this->SetLineWidth(0.35);
        $this->Line($x, $y + 5.1, $x + $w, $y + 5.1);
        $this->SetLineWidth(0.22);
    }

    private function checkbox(float $x, float $y, string $label, bool $on): void
    {
        $s = 3.6;
        $this->SetLineWidth(0.25);
        $this->Rect($x, $y, $s, $s);
        if ($on) {
            $this->SetLineWidth(0.45);
            $this->Line($x + 0.6, $y + 1.9, $x + 1.45, $y + 2.85);
            $this->Line($x + 1.45, $y + 2.85, $x + 3.05, $y + 0.65);
            $this->SetLineWidth(0.25);
        }
        $this->SetXY($x + $s + 1.6, $y - 0.35);
        $this->Cell(70, $s + 0.7, $this->t($label));
    }

    private function fieldBox(float $x, float $y, float $w, float $h, string $label, string $value): void
    {
        $this->SetLineWidth(0.22);
        $this->Rect($x, $y, $w, $h);
        $this->SetFont('Helvetica', '', 6.6);
        $this->SetTextColor(60, 60, 60);
        $this->SetXY($x + 1.3, $y + 0.7);
        $this->Cell($w - 2.6, 3.0, $this->t($label));
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Helvetica', '', 9.5);
        $this->SetXY($x + 1.3, $y + 4.0);
        $this->Cell($w - 2.6, 5.5, $this->t($value));
    }

    private function drawAidBoxes(float $x, float $y, float $totalW, string $id): void
    {
        $groups = 6;
        $groupGap = 3.2;
        $boxGap = 0.7;
        $inner = $totalW - (($groups - 1) * $groupGap);
        $box = ($inner - ($groups * 2 * $boxGap)) / 18;
        $h = 8.0;
        $cursor = $x;
        $this->SetFont('Helvetica', 'B', 10);
        $this->SetLineWidth(0.28);
        for ($g = 0; $g < $groups; $g++) {
            for ($i = 0; $i < 3; $i++) {
                $idx = $g * 3 + $i;
                $this->Rect($cursor, $y, $box, $h);
                $ch = $id[$idx] ?? '';
                if ($ch !== '') {
                    $this->SetXY($cursor, $y + 1.4);
                    $this->Cell($box, 5.4, $this->t($ch), 0, 0, 'C');
                }
                $cursor += $box + $boxGap;
            }
            $cursor += $groupGap - $boxGap;
        }
    }

    private function drawSignature(float $x, float $y, float $w, float $h): void
    {
        if ($this->signaturePng === '') {
            return;
        }
        $tmp = FULLMAKT_TMP . '/sig-' . bin2hex(random_bytes(6)) . '.png';
        if (file_put_contents($tmp, $this->signaturePng) === false) {
            return;
        }
        try {
            $info = @getimagesize($tmp);
            if ($info === false || empty($info[0]) || empty($info[1])) {
                return;
            }
            $iw = (float) $info[0];
            $ih = (float) $info[1];
            $scale = min($w / $iw, $h / $ih);
            $dw = $iw * $scale;
            $dh = $ih * $scale;
            $dx = $x + ($w - $dw) / 2;
            $dy = $y + ($h - $dh) / 2;
            $this->Image($tmp, $dx, $dy, $dw, $dh, 'PNG');
        } finally {
            @unlink($tmp);
        }
    }
}

function fullmakt_decode_signature_png(string $dataUrl): ?string
{
    $dataUrl = trim($dataUrl);
    if ($dataUrl === '') {
        return null;
    }
    if (preg_match('#^data:image/png;base64,(.+)$#s', $dataUrl, $m)) {
        $bin = base64_decode($m[1], true);
    } else {
        $bin = base64_decode($dataUrl, true);
    }
    if ($bin === false || strlen($bin) < 80 || strlen($bin) > 900000) {
        return null;
    }
    if (strncmp($bin, "\x89PNG\r\n\x1a\n", 8) !== 0) {
        return null;
    }
    return $bin;
}

function fullmakt_decode_b64_file(string $raw, int $maxBytes): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    if (preg_match('#^data:[^;]+;base64,(.+)$#s', $raw, $m)) {
        $bin = base64_decode($m[1], true);
    } else {
        $bin = base64_decode($raw, true);
    }
    if ($bin === false || $bin === '') {
        return null;
    }
    if (strlen($bin) > $maxBytes) {
        return null;
    }
    return $bin;
}

function fullmakt_signature_has_ink(string $png): bool
{
    if (!function_exists('imagecreatefromstring')) {
        return strlen($png) > 200;
    }
    $im = @imagecreatefromstring($png);
    if ($im === false) {
        return false;
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $ink = 0;
    $stepX = max(1, (int) floor($w / 100));
    $stepY = max(1, (int) floor($h / 60));
    for ($x = 0; $x < $w; $x += $stepX) {
        for ($y = 0; $y < $h; $y += $stepY) {
            $rgba = imagecolorat($im, $x, $y);
            $a = ($rgba & 0x7F000000) >> 24;
            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8) & 0xFF;
            $b = $rgba & 0xFF;
            if ($a > 110) {
                continue;
            }
            if ($r < 245 || $g < 245 || $b < 245) {
                $ink++;
                if ($ink >= 10) {
                    imagedestroy($im);
                    return true;
                }
            }
        }
    }
    imagedestroy($im);
    return false;
}

function fullmakt_signature_transparent(string $png): string
{
    if (!function_exists('imagecreatefromstring')) {
        return $png;
    }
    $im = @imagecreatefromstring($png);
    if ($im === false) {
        return $png;
    }
    imagealphablending($im, false);
    imagesavealpha($im, true);
    $w = imagesx($im);
    $h = imagesy($im);
    $clear = imagecolorallocatealpha($im, 255, 255, 255, 127);
    for ($x = 0; $x < $w; $x++) {
        for ($y = 0; $y < $h; $y++) {
            $rgba = imagecolorat($im, $x, $y);
            $a = ($rgba & 0x7F000000) >> 24;
            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8) & 0xFF;
            $b = $rgba & 0xFF;
            if ($a > 110 || ($r > 245 && $g > 245 && $b > 245)) {
                imagesetpixel($im, $x, $y, $clear);
            }
        }
    }
    ob_start();
    imagepng($im);
    imagedestroy($im);
    $out = ob_get_clean();
    return $out !== false && $out !== '' ? $out : $png;
}

function fullmakt_trim_signature_png(string $png): string
{
    if (!function_exists('imagecreatefromstring')) {
        return $png;
    }
    $im = @imagecreatefromstring($png);
    if ($im === false) {
        return $png;
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $minX = $w;
    $minY = $h;
    $maxX = -1;
    $maxY = -1;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgba = imagecolorat($im, $x, $y);
            $a = ($rgba & 0x7F000000) >> 24;
            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8) & 0xFF;
            $b = $rgba & 0xFF;
            if ($a > 110 || ($r > 245 && $g > 245 && $b > 245)) {
                continue;
            }
            if ($x < $minX) {
                $minX = $x;
            }
            if ($y < $minY) {
                $minY = $y;
            }
            if ($x > $maxX) {
                $maxX = $x;
            }
            if ($y > $maxY) {
                $maxY = $y;
            }
        }
    }
    if ($maxX < $minX) {
        imagedestroy($im);
        return $png;
    }
    $pad = 2;
    $minX = max(0, $minX - $pad);
    $minY = max(0, $minY - $pad);
    $maxX = min($w - 1, $maxX + $pad);
    $maxY = min($h - 1, $maxY + $pad);
    $cw = $maxX - $minX + 1;
    $ch = $maxY - $minY + 1;
    $crop = imagecreatetruecolor($cw, $ch);
    imagealphablending($crop, false);
    imagesavealpha($crop, true);
    $clear = imagecolorallocatealpha($crop, 255, 255, 255, 127);
    imagefilledrectangle($crop, 0, 0, $cw, $ch, $clear);
    imagealphablending($crop, true);
    imagecopy($crop, $im, 0, 0, $minX, $minY, $cw, $ch);
    imagedestroy($im);
    ob_start();
    imagepng($crop);
    imagedestroy($crop);
    $out = ob_get_clean();
    return $out !== false && $out !== '' ? $out : $png;
}

function fullmakt_fit_sig_box(string $png, array $box): array
{
    $iw = 70.0;
    $ih = 16.0;
    if (function_exists('imagecreatefromstring')) {
        $im = @imagecreatefromstring($png);
        if ($im !== false) {
            $iw = (float) max(1, imagesx($im));
            $ih = (float) max(1, imagesy($im));
            imagedestroy($im);
        }
    }
    $scale = min($box['w'] / $iw, $box['h'] / $ih);
    $dw = $iw * $scale;
    $dh = $ih * $scale;
    return [
        'x' => $box['x'],
        'y' => $box['y'] + $box['h'] - $dh,
        'w' => $dw,
        'h' => $dh,
    ];
}

function fullmakt_sig_box(array $doc): array
{
    $sig = $doc['sig'] ?? null;
    if (!is_array($sig)) {
        $sig = [];
    }
    return [
        'x' => (float) ($sig['x'] ?? $doc['sig_x'] ?? 28),
        'y' => (float) ($sig['y'] ?? $doc['sig_y'] ?? 198),
        'w' => (float) ($sig['w'] ?? $doc['sig_w'] ?? 70),
        'h' => (float) ($sig['h'] ?? $doc['sig_h'] ?? 16),
    ];
}

function fullmakt_write_tmp(string $bytes, string $suffix): string
{
    $path = FULLMAKT_TMP . '/ov-' . bin2hex(random_bytes(6)) . $suffix;
    if (file_put_contents($path, $bytes) === false) {
        throw new RuntimeException('Kunde inte skriva tempfil');
    }
    return $path;
}

function fullmakt_redact_log_line(string $line, string $token = '', string $path = ''): string
{
    if ($path !== '') {
        $line = str_replace($path, 'source.pdf', $line);
        $base = basename($path);
        if ($base !== '' && $base !== '.' && $base !== 'source.pdf') {
            $line = str_replace($base, 'source.pdf', $line);
        }
        $stem = pathinfo($path, PATHINFO_FILENAME);
        if (is_string($stem) && strlen($stem) >= 16) {
            $line = str_replace($stem, '[token]', $line);
        }
    }
    if ($token !== '') {
        $line = str_replace($token, '[token]', $line);
    }
    $line = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[mejl]', $line) ?? $line;
    return $line;
}

function fullmakt_try_fpdi(string $srcPdf, string $sigPng, array $box): ?string
{
    $autoload = FULLMAKT_ROOT . '/vendor/fpdi/autoload.php';
    if (!is_file($autoload)) {
        return null;
    }
    require_once FULLMAKT_ROOT . '/vendor/fpdf/fpdf.php';
    require_once $autoload;
    $tmp = fullmakt_write_tmp($sigPng, '.png');
    try {
        $pdf = new \setasign\Fpdi\Fpdi('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(false);
        $pdf->SetCompression(true);
        $pageCount = $pdf->setSourceFile($srcPdf);
        if ($pageCount < 1) {
            return null;
        }
        for ($i = 1; $i <= $pageCount; $i++) {
            $tpl = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($tpl);
            $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
            $pdf->AddPage($orientation, [$size['width'], $size['height']]);
            $pdf->useTemplate($tpl);
            if ($i === 1) {
                $pdf->Image($tmp, $box['x'], $box['y'], $box['w'], $box['h'], 'PNG');
            }
        }
        return $pdf->Output('S');
    } catch (Throwable $e) {
        fullmakt_log('overlay.log', 'fpdi ' . fullmakt_redact_log_line($e->getMessage(), '', $srcPdf));
        return null;
    } finally {
        @unlink($tmp);
    }
}

function fullmakt_overlay_png_fallback(string $previewPath, string $sigPng, array $box): string
{
    if (!function_exists('imagecreatefrompng') || !is_readable($previewPath)) {
        throw new RuntimeException('PNG-förhandsvisning saknas för fallback');
    }
    $page = @imagecreatefrompng($previewPath);
    if ($page === false) {
        $raw = file_get_contents($previewPath);
        $page = $raw !== false ? @imagecreatefromstring($raw) : false;
    }
    if ($page === false) {
        throw new RuntimeException('Kunde inte läsa förhandsvisning');
    }
    $sigIm = @imagecreatefromstring($sigPng);
    if ($sigIm === false) {
        imagedestroy($page);
        throw new RuntimeException('Kunde inte läsa signatur');
    }
    imagealphablending($page, true);
    imagesavealpha($page, true);
    $pw = imagesx($page);
    $ph = imagesy($page);
    $sx = (int) round($box['x'] / 210.0 * $pw);
    $sy = (int) round($box['y'] / 297.0 * $ph);
    $sw = max(1, (int) round($box['w'] / 210.0 * $pw));
    $sh = max(1, (int) round($box['h'] / 297.0 * $ph));
    imagecopyresampled($page, $sigIm, $sx, $sy, 0, 0, $sw, $sh, imagesx($sigIm), imagesy($sigIm));
    imagedestroy($sigIm);
    $tmp = FULLMAKT_TMP . '/page-' . bin2hex(random_bytes(6)) . '.png';
    imagepng($page, $tmp);
    imagedestroy($page);
    try {
        require_once FULLMAKT_ROOT . '/vendor/fpdf/fpdf.php';
        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(false);
        $pdf->SetCompression(true);
        $pdf->AddPage();
        $pdf->Image($tmp, 0, 0, 210, 297, 'PNG');
        return $pdf->Output('S');
    } finally {
        @unlink($tmp);
    }
}

/**
 * @return array{0: string, 1: string} pdf bytes, method
 */
function fullmakt_build_overlay_pdf(array $doc, string $signaturePng): array
{
    $token = (string) ($doc['token'] ?? '');
    $src = fullmakt_original_pdf_path($token);
    $preview = fullmakt_preview_png_path($token);
    $box = fullmakt_sig_box($doc);
    $sig = fullmakt_trim_signature_png(fullmakt_signature_transparent($signaturePng));
    $box = fullmakt_fit_sig_box($sig, $box);

    if (is_readable($src)) {
        $fpdi = fullmakt_try_fpdi($src, $sig, $box);
        if (is_string($fpdi) && strncmp($fpdi, '%PDF', 4) === 0) {
            return [$fpdi, 'fpdi'];
        }
    }
    if (is_readable($preview)) {
        return [fullmakt_overlay_png_fallback($preview, $sig, $box), 'png'];
    }
    throw new RuntimeException('Kunde inte stämpla PDF (varken FPDI eller PNG-fallback)');
}

function fullmakt_build_ellevio_pdf(array $doc, string $signaturePng): string
{
    $now = fullmakt_now();
    $ort = trim((string) ($doc['ort'] ?? ''));
    $datumOrt = $now->format('Y-m-d') . ($ort !== '' ? ', ' . $ort : '');
    $aid = preg_replace('/\D+/', '', (string) ($doc['anlaggnings_id'] ?? '')) ?? '';
    $pdf = new FullmaktPdf($doc, $aid, $datumOrt, $signaturePng);
    return $pdf->render();
}

function fullmakt_build_pdf(array $doc, string $signaturePng): array
{
    if (fullmakt_doc_type($doc) === 'overlay') {
        return fullmakt_build_overlay_pdf($doc, $signaturePng);
    }
    return [fullmakt_build_ellevio_pdf($doc, $signaturePng), 'ellevio'];
}
