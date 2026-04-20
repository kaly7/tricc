<?php
/**
 * Extract "Adójel" (tax id) from pdftotext output.
 * Typical line on payslip: Adójel: 8375171573
 */
class TaxIdExtractor {
    public static function extract(string $text): ?string {
        if (preg_match('/Adójel\s*:\s*([0-9]{10})/u', $text, $m)) return highlight_digits($m[1]);
        if (preg_match('/Adojel\s*:\s*([0-9]{10})/u', $text, $m)) return highlight_digits($m[1]);
        return null;
    }
}
function highlight_digits(string $s): string {
    // keep digits only, just in case
    return preg_replace('/\D+/', '', $s);
}
