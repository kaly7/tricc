<?php
namespace Services;

class EmployeeService {

    public static function normalizeName(string $name): string {
        $name = trim($name);
        $name = preg_replace('/\s+/u', ' ', $name);
        $name = mb_strtolower($name, 'UTF-8');

        if (class_exists('\Transliterator')) {
            $tr = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            if ($tr) $name = $tr->transliterate($name);
        }

        $name = preg_replace('/[^a-z0-9 \-]/', '', $name);
        $name = trim(preg_replace('/\s+/', ' ', $name));
        return $name;
    }

    public static function findByNorm(string $nameNorm): ?array {
        $pdo = \Db::pdo();
        $stmt = $pdo->prepare("SELECT id, name, email FROM employees WHERE name_norm = ? AND active=1 LIMIT 1");
        $stmt->execute([$nameNorm]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
