<?php
/**
 * PDF of Amministrazione › Statistiche (admin/stats.php?pdf=1), A4 portrait, built with FPDF
 * (includes/lib/fpdf, core Helvetica font: text is converted to Windows-1252).
 * Summary, chart per day / month, table per service, day-by-day (or month) table with the
 * average wait; the header row repeats on every page.
 */
require_once __DIR__ . '/lib/fpdf/fpdf.php';

class StatsPdf extends FPDF
{
    private const BLUE = [2, 132, 199];
    private const INK  = [12, 59, 86];
    private const GREY = [100, 116, 139];
    private const LINE = [214, 235, 247];

    private string $title;
    private string $period;
    private string $footer;

    public function __construct(string $title, string $period, string $footer)
    {
        parent::__construct('P', 'mm', 'A4');
        $this->title  = $title;
        $this->period = $period;
        $this->footer = $footer;
        $this->SetMargins(15, 15, 15);
        $this->SetAutoPageBreak(true, 18);
        $this->AliasNbPages();
        $this->SetTitle($this->t($title));
        $this->SetCreator('Eliminacode');
    }

    /** UTF-8 → the core font's Windows-1252. */
    public function t(string $s): string
    {
        $r = @iconv('UTF-8', 'windows-1252//TRANSLIT', $s);
        return $r === false ? $s : $r;
    }

    public function Header(): void
    {
        $this->SetFont('Helvetica', 'B', 15);
        $this->SetTextColor(...self::INK);
        $this->Cell(0, 7, $this->t($this->title), 0, 1);
        $this->SetFont('Helvetica', '', 9.5);
        $this->SetTextColor(...self::GREY);
        $this->Cell(120, 5, $this->t($this->period), 0, 0);
        $this->Cell(0, 5, $this->t('Generato il ' . date('d/m/Y H:i')), 0, 1, 'R');
        $this->SetDrawColor(...self::BLUE);
        $this->SetLineWidth(.6);
        $this->Line(15, $this->GetY() + 1.5, 195, $this->GetY() + 1.5);
        $this->SetLineWidth(.2);
        $this->Ln(5);
    }

    public function Footer(): void
    {
        $this->SetY(-12);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(...self::GREY);
        $this->Cell(90, 5, $this->t($this->footer), 0, 0);
        $this->Cell(0, 5, $this->t('Pagina ' . $this->PageNo() . ' di {nb}'), 0, 0, 'R');
    }

    public function section(string $title): void
    {
        if ($this->GetY() > 250) {
            $this->AddPage();
        }
        $this->Ln(3);
        $this->SetFont('Helvetica', 'B', 11.5);
        $this->SetTextColor(...self::INK);
        $this->Cell(0, 7, $this->t($title), 0, 1);
    }

    /** Summary boxes in one row: [[label, value, note], …]. */
    public function tiles(array $tiles): void
    {
        $gap = 4;
        $w = (180 - $gap * (count($tiles) - 1)) / count($tiles);
        $y = $this->GetY();
        foreach ($tiles as $i => [$label, $value, $note]) {
            $x = 15 + $i * ($w + $gap);
            $this->SetDrawColor(...self::LINE);
            $this->SetFillColor(248, 251, 253);
            $this->Rect($x, $y, $w, 24, 'DF');
            $this->SetXY($x + 3, $y + 2.5);
            $this->SetFont('Helvetica', '', 8.5);
            $this->SetTextColor(...self::GREY);
            $this->Cell($w - 6, 4, $this->t($label), 0, 2);
            $this->SetFont('Helvetica', 'B', 15);
            $this->SetTextColor(...self::INK);
            $this->Cell($w - 6, 9, $this->t($value), 0, 2);
            $this->SetFont('Helvetica', '', 7.5);
            $this->SetTextColor(...self::GREY);
            $this->Cell($w - 6, 4, $this->t($note), 0, 2);
        }
        $this->SetY($y + 28);
    }

    /** Bar chart: [label => value], first / last label under the axis. */
    public function bars(array $values, string $firstLabel, string $lastLabel): void
    {
        $h = 42;
        $max = max(1, ...array_values($values));
        $y0 = $this->GetY() + 4;
        $n = count($values);
        $slot = 180 / max(1, $n);
        $bw = max(.4, $slot * .72);
        $this->SetDrawColor(...self::LINE);
        $this->SetFont('Helvetica', '', 7);
        $this->SetTextColor(...self::GREY);
        foreach ([.5, 1] as $g) {
            $gy = $y0 + $h - $h * $g;
            $this->Line(15, $gy, 195, $gy);
            $this->Text(15, $gy - 1, (string) round($max * $g));
        }
        $this->SetFillColor(...self::BLUE);
        $i = 0;
        foreach ($values as $v) {
            $bh = $h * $v / $max;
            if ($bh > 0) {
                $this->Rect(15 + $i * $slot + ($slot - $bw) / 2, $y0 + $h - $bh, $bw, $bh, 'F');
            }
            $i++;
        }
        $this->SetDrawColor(...self::GREY);
        $this->Line(15, $y0 + $h, 195, $y0 + $h);
        $this->SetXY(15, $y0 + $h + 1);
        $this->Cell(90, 4, $this->t($firstLabel), 0, 0);
        $this->Cell(90, 4, $this->t($lastLabel), 0, 1, 'R');
        $this->Ln(2);
    }

    /**
     * Table: $cols = [[title, width, align], …], $rows = [[cell, …], …]; a cell can be
     * ['text' => …, 'dot' => '#rrggbb'] for a coloured dot before the text. $foot = last row in bold.
     */
    public function table(array $cols, array $rows, ?array $foot = null): void
    {
        $head = function () use ($cols) {
            $this->SetFont('Helvetica', 'B', 8.5);
            $this->SetTextColor(255, 255, 255);
            $this->SetFillColor(...self::BLUE);
            foreach ($cols as [$title, $w, $align]) {
                $this->Cell($w, 6.5, $this->t($title), 0, 0, $align, true);
            }
            $this->Ln();
        };
        $head();
        $line = function (array $r, bool $bold, bool $fill) use ($cols, $head) {
            if ($this->GetY() > 297 - 18 - 6) {
                $this->AddPage();
                $head();
            }
            $this->SetFont('Helvetica', $bold ? 'B' : '', 8.5);
            $this->SetTextColor(...self::INK);
            $this->SetFillColor(240, 247, 252);
            foreach ($cols as $i => [, $w, $align]) {
                $c = $r[$i] ?? '';
                if (is_array($c)) {
                    $x = $this->GetX();
                    $y = $this->GetY();
                    $this->Cell($w, 6, '', 0, 0, 'L', $fill);
                    [$rr, $gg, $bb] = sscanf($c['dot'], '#%02x%02x%02x');
                    $this->SetFillColor($rr ?? 2, $gg ?? 132, $bb ?? 199);
                    $this->Rect($x + 1.5, $y + 1.8, 2.4, 2.4, 'F');
                    $this->SetFillColor(240, 247, 252);
                    $this->SetXY($x + 5.5, $y);
                    $this->Cell($w - 5.5, 6, $this->t((string) $c['text']), 0, 0, 'L');
                } else {
                    $this->Cell($w, 6, $this->t((string) $c), 0, 0, $align, $fill);
                }
            }
            $this->Ln();
        };
        foreach (array_values($rows) as $k => $r) {
            $line($r, false, $k % 2 === 1);
        }
        if ($foot) {
            $this->SetDrawColor(...self::GREY);
            $this->Line(15, $this->GetY(), 195, $this->GetY());
            $line($foot, true, false);
        }
    }

    public function note(string $s): void
    {
        $this->Ln(2);
        $this->SetFont('Helvetica', 'I', 7.5);
        $this->SetTextColor(...self::GREY);
        $this->MultiCell(0, 3.8, $this->t($s));
    }
}
