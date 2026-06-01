<?php
/**
 * EmployeeUpsert
 * Auto-create employees by tax_id during PDF processing, compatible with schemas where
 * employees.name_norm is NOT NULL (no default).
 *
 * Behavior:
 * - If employee exists by tax_id => return id (optionally update name/name_norm if different)
 * - If not exists => INSERT with email NULL, and name_norm if the column exists.
 */
class EmployeeUpsert {
    private static function hasColumn(PDO $pdo, string $table, string $col): bool {
        $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $st->execute([$col]);
        return (bool)$st->fetch(PDO::FETCH_ASSOC);
    }

    private static function normalizeTaxId(string $taxId): string {
        return preg_replace('/\D+/', '', $taxId);
    }

    /**
     * HR-ből szinkronizál egy rekordot a payslip.employees cache-be.
     * Az effektív emailt (céges vagy privát, a payslip_email_target alapján) menti.
     * Visszatér a payslip.employees.id-val.
     */
    public static function upsertFromHr(PDO $pdo, array $hrEmp, string $nameNorm = ''): int {
        $taxId = self::normalizeTaxId((string)($hrEmp['tax_id'] ?? ''));
        $name  = (string)($hrEmp['full_name'] ?? '');
        $hrId  = (int)($hrEmp['id'] ?? 0);

        $effectiveEmail = (($hrEmp['payslip_email_target'] ?? 'ceges') === 'privat' && !empty($hrEmp['email_private']))
            ? (string)$hrEmp['email_private']
            : (string)($hrEmp['email'] ?? '');
        if ($effectiveEmail === '') $effectiveEmail = null;

        if ($nameNorm === '') $nameNorm = mb_strtolower($name, 'UTF-8');

        // Upsert tax_id alapján (UNIQUE)
        $pdo->prepare("
            INSERT INTO employees (name, name_norm, email, tax_id, hr_id)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              name      = VALUES(name),
              name_norm = VALUES(name_norm),
              email     = VALUES(email),
              hr_id     = VALUES(hr_id)
        ")->execute([$name, $nameNorm, $effectiveEmail, $taxId, $hrId]);

        $st = $pdo->prepare("SELECT id FROM employees WHERE tax_id=? LIMIT 1");
        $st->execute([$taxId]);
        return (int)($st->fetchColumn() ?: $pdo->lastInsertId());
    }

    public static function upsertByTaxId(PDO $pdo, string $name, string $taxId, string $nameNorm = ''): int {
        $taxId = self::normalizeTaxId($taxId);
        if (!preg_match('/^\d{10}$/', $taxId)) {
            throw new RuntimeException('Invalid tax_id');
        }

        $st = $pdo->prepare("SELECT id, name FROM employees WHERE tax_id=? LIMIT 1");
        $st->execute([$taxId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        $hasNameNorm = self::hasColumn($pdo, 'employees', 'name_norm');

        if ($row) {
            $id = (int)$row['id'];
            $oldName = (string)($row['name'] ?? '');
            if ($name !== '' && $oldName !== $name) {
                if ($hasNameNorm) {
                    $pdo->prepare("UPDATE employees SET name=?, name_norm=? WHERE id=?")
                        ->execute([$name, ($nameNorm !== '' ? $nameNorm : null), $id]);
                } else {
                    $pdo->prepare("UPDATE employees SET name=? WHERE id=?")->execute([$name, $id]);
                }
            }
            return $id;
        }

        if ($hasNameNorm) {
            if ($nameNorm === '') {
                // If schema requires name_norm, we must provide something
                $nameNorm = mb_strtolower($name, 'UTF-8');
            }
            $pdo->prepare("INSERT INTO employees(name,email,tax_id,name_norm) VALUES(?,NULL,?,?)")
                ->execute([$name, $taxId, $nameNorm]);
        } else {
            $pdo->prepare("INSERT INTO employees(name,email,tax_id) VALUES(?,NULL,?)")
                ->execute([$name, $taxId]);
        }

        return (int)$pdo->lastInsertId();
    }
}
