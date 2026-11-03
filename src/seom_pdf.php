<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

require_once dirname(__DIR__) . '/fullmakt/vendor/fpdf/fpdf.php';

/**
 * Sollentuna Elnät AB: föranmälan (sida 1) och installationsmedgivande/färdiganmälan (sida 2).
 * Fälten kommer färdigmappade. Klassen ritar, den slår inte upp ordern.
 */
final class SeomFullmaktPdf extends FPDF
{
    /** @param array<string, mixed> $doc */
    public function __construct(private array $doc)
    {
        parent::__construct('P', 'mm', 'A4');
        $this->SetAutoPageBreak(false);
        $this->SetMargins(12, 10, 12);
        $this->SetCompression(false);
    }

    public function render(): string
    {
        $this->AddPage();
        $this->drawForanmalan();
        $this->AddPage();
        $this->drawMedgivande();
        $bytes = $this->Output('S');
        return is_string($bytes) ? $bytes : '';
    }

    private function t(string $s): string
    {
        $out = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s);
        return $out === false ? $s : $out;
    }

    private function drawForanmalan(): void
    {
        $x = 12.0;
        $w = 186.0;
        $this->headerBlock($x, $w);
        $this->SetFont('Helvetica', 'B', 13);
        $this->SetXY($x, 28);
        $this->Cell($w, 6, $this->t('FÖRANMÄLAN ELINSTALLATION'), 0, 1, 'L');

        $y = 36.0;
        $this->field($x, $y, 92, 11, 'Kundens referensnummer', (string) ($this->doc['referensnummer'] ?? ''));
        $this->field($x + 94, $y, 92, 11, 'Offertnummer', (string) ($this->doc['offertnummer'] ?? ''));

        $y = 50.0;
        $this->section($x, $y, $w, 'ANMÄLAN AVSER');
        $y = 56.0;
        $this->SetFont('Helvetica', '', 8);
        $typA = !empty($this->doc['produktion_typ_a']);
        $this->checkbox($x, $y, 'Nyanslutning', false);
        $this->checkbox($x + 46, $y, 'Tillfällig', false);
        $this->checkbox($x + 82, $y, 'Ändring', false);
        $this->checkbox($x + 116, $y, 'Produktion typ A', $typA);
        $this->checkbox($x + 158, $y, 'Övrigt', false);

        $y = 64.0;
        $this->section($x, $y, $w, 'ANLÄGGNINGENS ADRESS');
        $y = 70.0;
        $this->field($x, $y, 120, 11, 'Adress', (string) ($this->doc['adress'] ?? ''));
        $this->field($x + 122, $y, 64, 11, 'Lgh nr', (string) ($this->doc['lgh'] ?? ''));
        $y = 82.0;
        $this->field($x, $y, 92, 11, 'Anläggnings-ID', (string) ($this->doc['anlaggnings_id'] ?? ''));
        $this->field($x + 94, $y, 40, 11, 'Postnr', (string) ($this->doc['postnummer'] ?? ''));
        $this->field($x + 136, $y, 50, 11, 'Ort', (string) ($this->doc['ort'] ?? ''));
        $y = 94.0;
        $this->field($x, $y, 92, 11, 'Mätarnummer', (string) ($this->doc['matarnummer'] ?? ''));
        $this->field($x + 94, $y, 92, 11, 'Fastighetsbeteckning', (string) ($this->doc['fastighetsbeteckning'] ?? ''));

        $y = 108.0;
        $this->section($x, $y, $w, 'KUND');
        $this->SetXY($x + 94, $y);
        $this->SetFont('Helvetica', 'B', 9);
        $this->Cell(92, 5, $this->t('FASTIGHETSÄGARE'));
        $y = 114.0;
        $half = 91.0;
        $kund = is_array($this->doc['kund'] ?? null) ? $this->doc['kund'] : [];
        $agare = is_array($this->doc['fastighetsagare'] ?? null) ? $this->doc['fastighetsagare'] : [];
        $this->party($x, $y, $half, $kund);
        $this->party($x + 95, $y, $half, $agare);
        $y = 186.0;
        $this->SetFont('Helvetica', '', 8);
        $ager = (string) ($this->doc['ager_fastighet'] ?? '') === 'ja';
        $this->checkbox($x, $y, 'Äger fastigheten: Ja', $ager);
        $this->checkbox($x + 52, $y, 'Nej', !$ager && (string) ($this->doc['ager_fastighet'] ?? '') === 'nej');

        $y = 193.0;
        $this->section($x, $y, $w, 'TYP AV KUNDANLÄGGNING');
        $y = 199.0;
        $enbostad = (string) ($this->doc['kundanlaggning'] ?? '') === 'Enbostadshus';
        $this->checkbox($x, $y, 'Enbostadshus', $enbostad);
        $this->checkbox($x + 40, $y, 'Fritidshus', false);
        $this->checkbox($x + 74, $y, 'Flerbostadshus', false);
        $this->checkbox($x + 116, $y, 'Lokal', false);
        $this->checkbox($x + 146, $y, 'Industri', false);

        $y = 206.5;
        $this->field($x, $y, 92, 11, 'Servissäkring / mätarsäkring befintlig', (string) ($this->doc['servissakring'] ?? ''));
        $this->field($x + 94, $y, 92, 11, '3-fas abonnemang befintlig', (string) ($this->doc['trefas'] ?? ''));
        $y = 218.5;
        $this->field($x, $y, $w, 11, 'Beräknad färdig anläggning', (string) ($this->doc['fardig_datum'] ?? ''));

        $y = 232.0;
        $this->section($x, $y, $w, 'INSTALLATÖR');
        $inst = is_array($this->doc['installator'] ?? null) ? $this->doc['installator'] : [];
        $y = 238.0;
        $this->field($x, $y, 110, 10, 'Företag', (string) ($inst['foretag'] ?? ''));
        $this->field($x + 112, $y, 74, 10, 'Org.nr', (string) ($inst['orgnr'] ?? ''));
        $y = 248.5;
        $this->field($x, $y, 110, 10, 'Adress', trim((string) ($inst['adress'] ?? '') . ' ' . (string) ($inst['postnummer'] ?? '') . ' ' . (string) ($inst['ort'] ?? '')));
        $this->field($x + 112, $y, 74, 10, 'Telefon', (string) ($inst['telefon'] ?? ''));
        $y = 259.0;
        $this->field($x, $y, $w, 10, 'Handläggare', (string) ($inst['handlaggare'] ?? ''));
        $this->signatures($x, 272.0, $w, (string) ($inst['handlaggare'] ?? ''), (string) ($kund['namn'] ?? ''));
    }

    private function drawMedgivande(): void
    {
        $x = 12.0;
        $w = 186.0;
        $this->headerBlock($x, $w);
        $this->SetFont('Helvetica', 'B', 12);
        $this->SetXY($x, 28);
        $this->MultiCell($w, 6, $this->t('INSTALLATIONSMEDGIVANDE & FÄRDIGANMÄLAN AV ELINSTALLATION'));

        $y = 44.0;
        $this->SetFont('Helvetica', '', 8);
        $this->SetXY($x, $y);
        $this->MultiCell($w, 4, $this->t(
            'Installatören anmäler elinstallationen hos Sollentuna Elnät AB. Kunden medger att uppgifterna lämnas och att installationen färdiganmäls när den är klar. Blanketten är ifylld från ordern. Underskrifterna lämnas av installatör och kund.'
        ));

        $y = 62.0;
        $this->field($x, $y, 92, 11, 'Kundens referensnummer', (string) ($this->doc['referensnummer'] ?? ''));
        $this->field($x + 94, $y, 92, 11, 'Offertnummer', (string) ($this->doc['offertnummer'] ?? ''));
        $y = 75.0;
        $this->section($x, $y, $w, 'ANLÄGGNINGENS ADRESS');
        $y = 81.0;
        $this->field($x, $y, 120, 11, 'Adress', (string) ($this->doc['adress'] ?? ''));
        $this->field($x + 122, $y, 64, 11, 'Lgh nr', (string) ($this->doc['lgh'] ?? ''));
        $y = 93.0;
        $this->field($x, $y, 92, 11, 'Anläggnings-ID', (string) ($this->doc['anlaggnings_id'] ?? ''));
        $this->field($x + 94, $y, 40, 11, 'Postnr', (string) ($this->doc['postnummer'] ?? ''));
        $this->field($x + 136, $y, 50, 11, 'Ort', (string) ($this->doc['ort'] ?? ''));
        $y = 105.0;
        $this->field($x, $y, 92, 11, 'Mätarnummer', (string) ($this->doc['matarnummer'] ?? ''));
        $this->field($x + 94, $y, 92, 11, 'Fastighetsbeteckning', (string) ($this->doc['fastighetsbeteckning'] ?? ''));

        $kund = is_array($this->doc['kund'] ?? null) ? $this->doc['kund'] : [];
        $y = 119.0;
        $this->section($x, $y, $w, 'KUND');
        $y = 125.0;
        $this->party($x, $y, 186, $kund);

        $inst = is_array($this->doc['installator'] ?? null) ? $this->doc['installator'] : [];
        $y = 198.0;
        $this->section($x, $y, $w, 'INSTALLATÖR');
        $y = 204.0;
        $this->field($x, $y, 110, 11, 'Företag', (string) ($inst['foretag'] ?? ''));
        $this->field($x + 112, $y, 74, 11, 'Org.nr', (string) ($inst['orgnr'] ?? ''));
        $y = 216.0;
        $this->field($x, $y, 110, 11, 'Adress', trim((string) ($inst['adress'] ?? '') . ' ' . (string) ($inst['postnummer'] ?? '') . ' ' . (string) ($inst['ort'] ?? '')));
        $this->field($x + 112, $y, 74, 11, 'Telefon', (string) ($inst['telefon'] ?? ''));
        $y = 228.0;
        $this->field($x, $y, $w, 11, 'Handläggare', (string) ($inst['handlaggare'] ?? ''));
        $y = 242.0;
        $this->field($x, $y, $w, 11, 'Beräknad färdig anläggning', (string) ($this->doc['fardig_datum'] ?? ''));
        $this->signatures($x, 258.0, $w, (string) ($inst['handlaggare'] ?? ''), (string) ($kund['namn'] ?? ''));
    }

    private function headerBlock(float $x, float $w): void
    {
        $this->SetFillColor(0, 84, 64);
        $this->Rect($x, 10, $w, 16, 'F');
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Helvetica', 'B', 11);
        $this->SetXY($x + 2, 12);
        $this->Cell(110, 5, $this->t('Sollentuna Elnät AB'), 0, 0, 'L');
        $this->SetFont('Helvetica', '', 8);
        $this->SetXY($x + 100, 12);
        $this->Cell($w - 104, 4, $this->t('Box 972, 191 29 Sollentuna'), 0, 2, 'R');
        $this->Cell($w - 104, 4, $this->t('08-000 00 07'), 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
    }

    private function section(float $x, float $y, float $w, string $title): void
    {
        $this->SetFont('Helvetica', 'B', 9);
        $this->SetXY($x, $y);
        $this->Cell($w, 4.5, $this->t($title));
        $this->SetDrawColor(0, 84, 64);
        $this->SetLineWidth(0.35);
        $this->Line($x, $y + 5.0, $x + $w, $y + 5.0);
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.2);
    }

    private function checkbox(float $x, float $y, string $label, bool $on): void
    {
        $s = 3.4;
        $this->SetLineWidth(0.25);
        $this->Rect($x, $y, $s, $s);
        if ($on) {
            $this->SetLineWidth(0.45);
            $this->Line($x + 0.55, $y + 1.7, $x + 1.35, $y + 2.7);
            $this->Line($x + 1.35, $y + 2.7, $x + 2.9, $y + 0.55);
            $this->SetLineWidth(0.25);
        }
        $this->SetFont('Helvetica', '', 7.5);
        $this->SetXY($x + $s + 1.2, $y - 0.4);
        $this->Cell(40, $s + 0.8, $this->t($label));
    }

    private function field(float $x, float $y, float $w, float $h, string $label, string $value): void
    {
        $this->SetLineWidth(0.2);
        $this->Rect($x, $y, $w, $h);
        $this->SetFont('Helvetica', '', 6.2);
        $this->SetTextColor(70, 70, 70);
        $this->SetXY($x + 1.2, $y + 0.6);
        $this->Cell($w - 2.4, 3, $this->t($label));
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Helvetica', '', 8.5);
        $this->SetXY($x + 1.2, $y + 3.8);
        $this->Cell($w - 2.4, 5, $this->t($value));
    }

    /** @param array<string, mixed> $party */
    private function party(float $x, float $y, float $w, array $party): void
    {
        $this->field($x, $y, $w, 10, 'Namn', (string) ($party['namn'] ?? ''));
        $this->field($x, $y + 10, $w, 10, 'Personnummer', (string) ($party['personnummer'] ?? ''));
        $this->field($x, $y + 20, $w, 10, 'Utdelningsadress', (string) ($party['adress'] ?? ''));
        $half = $w / 2;
        $this->field($x, $y + 30, $half, 10, 'Postnr', (string) ($party['postnummer'] ?? ''));
        $this->field($x + $half, $y + 30, $half, 10, 'Ort', (string) ($party['ort'] ?? ''));
        $this->field($x, $y + 40, $half, 10, 'Telefon', (string) ($party['telefon'] ?? ''));
        $this->field($x + $half, $y + 40, $half, 10, 'Mejl', (string) ($party['mejl'] ?? ''));
    }

    private function signatures(float $x, float $y, float $w, string $installer, string $customer): void
    {
        $gap = 4.0;
        $box = ($w - $gap) / 2;
        $h = 18.0;
        $this->Rect($x, $y, $box, $h);
        $this->Rect($x + $box + $gap, $y, $box, $h);
        $this->SetFont('Helvetica', '', 6.5);
        $this->SetXY($x + 1.2, $y + 1);
        $this->Cell($box - 2, 3, $this->t('Installatörens underskrift'));
        $this->SetXY($x + $box + $gap + 1.2, $y + 1);
        $this->Cell($box - 2, 3, $this->t('Kundens underskrift'));
        $this->SetFont('Helvetica', '', 8);
        $this->SetXY($x + 1.2, $y + 12);
        $this->Cell($box - 2, 4, $this->t($installer));
        $this->SetXY($x + $box + $gap + 1.2, $y + 12);
        $this->Cell($box - 2, 4, $this->t($customer));
    }
}
