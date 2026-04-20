<?php
/**
 * Mask Hungarian tax id (adóazonosító jel):
 * shows only last 3 digits, rest as *
 */
function maskTaxId(?string $taxId): string {
    if (!$taxId) return '';
    $taxId = preg_replace('/\D+/', '', $taxId);
    $len = strlen($taxId);
    if ($len <= 3) return $taxId;
    return str_repeat('*', $len - 3) . substr($taxId, -3);
}
