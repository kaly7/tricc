<?php
namespace Services;

class PdfService {

    public static function getTotalPages(string $pdfPath): int {
        $cmd = escapeshellcmd(BIN_PDFINFO) . ' ' . escapeshellarg($pdfPath);
        $out = shell_exec($cmd);
        if (!$out) return 0;
        if (preg_match('/^Pages:\s+(\d+)/mi', $out, $m)) return (int)$m[1];
        return 0;
    }

    /**
     * qpdf split: biztos megoldás
     * qpdf --split-pages input.pdf /out/dir/page.pdf
     * => /out/dir/page-1.pdf, page-2.pdf, ...
     */
    public static function splitToPages(string $pdfPath, string $outDir): void {
        if (!is_dir($outDir)) mkdir($outDir, 0770, true);

        $baseOut = rtrim($outDir, '/') . '/page.pdf';

        $cmd = escapeshellcmd(BIN_QPDF)
            . " --split-pages "
            . escapeshellarg($pdfPath) . " "
            . escapeshellarg($baseOut);

        $ret = 0;
        system($cmd, $ret);
        if ($ret !== 0) {
            throw new \RuntimeException("qpdf split failed with code $ret");
        }
    }

    /**
     * Név kinyerés:
     * A pdftotext kimenetben nálad így néz ki:
     *
     * Név:
     *
     * Beke Attila
     *
     * Adójel: ...
     *
     * Tehát:
     * - megkeressük a "Név:" sort
     * - ha a sorban van már név, visszaadjuk
     * - különben a következő nem üres sort adjuk vissza
     */
    public static function extractNameFromPagePdf(string $pagePdfPath): ?string {
        $cmd = escapeshellcmd(BIN_PDFTOTEXT) . ' ' . escapeshellarg($pagePdfPath) . ' -';
        $text = shell_exec($cmd);
        if (!$text) return null;

        $lines = preg_split('/\R/u', $text);
        $n = count($lines);

        for ($i = 0; $i < $n; $i++) {
            $line = trim($lines[$i]);

            if (preg_match('/^Név:\s*(.*)$/u', $line, $m)) {
                $rest = trim($m[1]);

                // 1) ha ugyanazon sorban már ott van a név
                if ($rest !== '') {
                    return preg_replace('/\s+/u', ' ', $rest);
                }

                // 2) külön sorban van: első nem üres sor a "Név:" után
                for ($j = $i + 1; $j < $n; $j++) {
                    $cand = trim($lines[$j]);
                    if ($cand !== '') {
                        return preg_replace('/\s+/u', ' ', $cand);
                    }
                }

                return null;
            }
        }

        return null;
    }

    public static function safeFileName(string $name): string {
        // normalizeName az EmployeeService-ben van
        $n = \Services\EmployeeService::normalizeName($name);
        $n = preg_replace('/\s+/', '_', $n);
        $n = preg_replace('/_+/', '_', $n);
        $n = trim($n, '_');
        return $n ?: 'unknown';
    }
}