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
