<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/config.php';
require_once APP_ROOT . '/app/core/Db.php';

if (php_sapi_name() !== 'cli') {
    exit("Ez a script csak CLI-ból futtatható.\n");
}

$argv = $_SERVER['argv'] ?? [];
$script = $argv[0] ?? 'import.php';
$csvFile = $argv[1] ?? null;
$dryRun = in_array('--dry-run', $argv, true);

if (!$csvFile) {
    exit("Használat: php {$script} /utvonal/fajl.csv [--dry-run]\n");
}

if (!is_file($csvFile)) {
    exit("CSV fájl nem található: {$csvFile}\n");
}

function normalize(?string $v): ?string {
    if ($v === null) return null;
    $v = trim($v);
    return $v === '' ? null : $v;
}

function parseDateValue(?string $value): ?string {
    $value = normalize($value);
    if ($value === null) return null;

    $value = str_replace(["\xC2\xA0", ' '], '', $value);

    if (preg_match('~^(\d{1,2})/(\d{1,2})/(\d{2,4})$~', $value, $m)) {
        $month = (int)$m[1];
        $day   = (int)$m[2];
        $year  = (int)$m[3];

        if ($year < 100) {
            $year = ($year <= 29) ? (2000 + $year) : (1900 + $year);
        }

        if (checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }

    if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $value)) {
        return $value;
    }

    return null;
}

function findHeaderIndex(array $headerMap, array $candidates): ?int {
    foreach ($candidates as $name) {
        if (array_key_exists($name, $headerMap)) {
            return $headerMap[$name];
        }
    }
    return null;
}

function getCell(array $row, ?int $idx): ?string {
    if ($idx === null) return null;
    return isset($row[$idx]) ? trim((string)$row[$idx]) : null;
}

$db = new Db();
$pdo = $db->pdo();

$handle = fopen($csvFile, 'r');
if (!$handle) {
    exit("Nem sikerült megnyitni a CSV-t.\n");
}

$header = fgetcsv($handle, 0, ';');
if (!$header) {
    exit("A CSV üres vagy hibás.\n");
}

if (isset($header[0])) {
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
}

$headerMap = [];
foreach ($header as $i => $name) {
    $headerMap[trim((string)$name)] = $i;
}

$idxEmployeeNo = findHeaderIndex($headerMap, ['Azonosító', 'Külső azonosító']);
$idxTaxId      = findHeaderIndex($headerMap, ['Adójel']);
$idxTaj        = findHeaderIndex($headerMap, ['TAJ szám', 'Taj szám', 'TAJ']);
$idxFullName   = findHeaderIndex($headerMap, ['Teljes név']);
$idxBirthName  = findHeaderIndex($headerMap, ['Születési név']);
$idxMotherName = findHeaderIndex($headerMap, ['Anyja neve']);
$idxBirthPlace = findHeaderIndex($headerMap, ['Születési hely']);
$idxBirthDate  = findHeaderIndex($headerMap, ['Születési idő']);
$idxZip        = findHeaderIndex($headerMap, ['Lakcím irsz']);
$idxCity       = findHeaderIndex($headerMap, ['Lakcím helység']);
$idxAddrLine   = findHeaderIndex($headerMap, ['Lakcím cím']);
$idxEmail      = findHeaderIndex($headerMap, ['E-mail cím']);
$idxPhone      = findHeaderIndex($headerMap, ['Telefonszám']);
$idxDivision   = findHeaderIndex($headerMap, ['Részleg', 'Részleg név']);
$idxNotes      = findHeaderIndex($headerMap, ['Megjegyzés']);

if ($idxFullName === null) {
    exit("A 'Teljes név' oszlop nem található.\n");
}

$selectByEmpNo = $pdo->prepare("SELECT id FROM employees WHERE company_emp_no = ? LIMIT 1");
$selectByTaxId = $pdo->prepare("SELECT id FROM employees WHERE tax_id = ? LIMIT 1");

$insertEmployee = $pdo->prepare("
    INSERT INTO employees (
        full_name, birth_name, mother_name, birth_place, birth_date,
        addr_zip, addr_city, addr_line, company_emp_no, company_division,
        tax_id, taj, email, phone, notes, is_active
    ) VALUES (
        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1
    )
");

$updateEmployee = $pdo->prepare("
    UPDATE employees SET
        full_name = ?, birth_name = ?, mother_name = ?, birth_place = ?, birth_date = ?,
        addr_zip = ?, addr_city = ?, addr_line = ?, company_emp_no = ?, company_division = ?,
        tax_id = ?, taj = ?, email = ?, phone = ?, notes = ?, is_active = 1
    WHERE id = ?
");

$deleteEmails = $pdo->prepare("DELETE FROM employee_emails WHERE employee_id = ?");
$insertEmail = $pdo->prepare("INSERT INTO employee_emails (employee_id, label, email, sort_order) VALUES (?, 'primary', ?, 0)");

$deletePhones = $pdo->prepare("DELETE FROM employee_phones WHERE employee_id = ?");
$insertPhone = $pdo->prepare("INSERT INTO employee_phones (employee_id, label, phone, sort_order) VALUES (?, 'primary', ?, 0)");

$deleteHomeAddress = $pdo->prepare("DELETE FROM employee_addresses WHERE employee_id = ? AND type = 'home'");
$insertHomeAddress = $pdo->prepare("INSERT INTO employee_addresses (employee_id, postal_code, city, address_line, type) VALUES (?, ?, ?, ?, 'home')");

$created = 0;
$updated = 0;
$skipped = 0;
$rowNum = 1;

if (!$dryRun) {
    $pdo->beginTransaction();
}

try {
    while (($row = fgetcsv($handle, 0, ';')) !== false) {
        $rowNum++;

        $fullName = normalize(getCell($row, $idxFullName));
        if ($fullName === null) {
            $skipped++;
            echo "[SKIP] {$rowNum}. sor: üres név\n";
            continue;
        }

        $companyEmpNo = normalize(getCell($row, $idxEmployeeNo));
        $taxId = normalize(getCell($row, $idxTaxId));

        $data = [
            'full_name' => $fullName,
            'birth_name' => normalize(getCell($row, $idxBirthName)),
            'mother_name' => normalize(getCell($row, $idxMotherName)),
            'birth_place' => normalize(getCell($row, $idxBirthPlace)),
            'birth_date' => parseDateValue(getCell($row, $idxBirthDate)),
            'addr_zip' => normalize(getCell($row, $idxZip)),
            'addr_city' => normalize(getCell($row, $idxCity)),
            'addr_line' => normalize(getCell($row, $idxAddrLine)),
            'company_emp_no' => $companyEmpNo,
            'company_division' => normalize(getCell($row, $idxDivision)),
            'tax_id' => $taxId,
            'taj' => normalize(getCell($row, $idxTaj)),
            'email' => normalize(getCell($row, $idxEmail)),
            'phone' => normalize(getCell($row, $idxPhone)),
            'notes' => normalize(getCell($row, $idxNotes)),
        ];

        $employeeId = null;
        if ($companyEmpNo !== null) {
            $selectByEmpNo->execute([$companyEmpNo]);
            $employeeId = $selectByEmpNo->fetchColumn() ?: null;
        }
        if ($employeeId === null && $taxId !== null) {
            $selectByTaxId->execute([$taxId]);
            $employeeId = $selectByTaxId->fetchColumn() ?: null;
        }

        if ($employeeId === null) {
            if ($dryRun) {
                echo "[CREATE] {$fullName}\n";
                $created++;
                continue;
            }

            $insertEmployee->execute([
                $data['full_name'], $data['birth_name'], $data['mother_name'], $data['birth_place'], $data['birth_date'],
                $data['addr_zip'], $data['addr_city'], $data['addr_line'], $data['company_emp_no'], $data['company_division'],
                $data['tax_id'], $data['taj'], $data['email'], $data['phone'], $data['notes'],
            ]);
            $employeeId = (int)$pdo->lastInsertId();
            $created++;
            echo "[CREATE] {$fullName} (#{$employeeId})\n";
        } else {
            if ($dryRun) {
                echo "[UPDATE] {$fullName} (#{$employeeId})\n";
                $updated++;
                continue;
            }

            $updateEmployee->execute([
                $data['full_name'], $data['birth_name'], $data['mother_name'], $data['birth_place'], $data['birth_date'],
                $data['addr_zip'], $data['addr_city'], $data['addr_line'], $data['company_emp_no'], $data['company_division'],
                $data['tax_id'], $data['taj'], $data['email'], $data['phone'], $data['notes'],
                (int)$employeeId,
            ]);
            $updated++;
            echo "[UPDATE] {$fullName} (#{$employeeId})\n";
        }

        if (!$dryRun && $employeeId !== null) {
            $deleteEmails->execute([(int)$employeeId]);
            if (!empty($data['email'])) {
                $insertEmail->execute([(int)$employeeId, $data['email']]);
            }

            $deletePhones->execute([(int)$employeeId]);
            if (!empty($data['phone'])) {
                $insertPhone->execute([(int)$employeeId, $data['phone']]);
            }

            $deleteHomeAddress->execute([(int)$employeeId]);
            if (!empty($data['addr_zip']) || !empty($data['addr_city']) || !empty($data['addr_line'])) {
                $insertHomeAddress->execute([(int)$employeeId, $data['addr_zip'], $data['addr_city'], $data['addr_line']]);
            }
        }
    }

    fclose($handle);

    if (!$dryRun) {
        $pdo->commit();
    }

    echo "\nKész.\n";
    echo "Létrehozva: {$created}\n";
    echo "Frissítve:  {$updated}\n";
    echo "Kihagyva:   {$skipped}\n";

} catch (Throwable $e) {
    if (!$dryRun && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fclose($handle);
    echo "\nHIBA: " . $e->getMessage() . "\n";
    exit(1);
}
