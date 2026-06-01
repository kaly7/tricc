<?php
declare(strict_types=1);

/**
 * HR jogosultság service.
 *
 * Tárolt statikus mezők csoportosítva (full_name nincs itt – az mindig látható).
 * load() az aktuális user jogosultságát adja vissza (request-szinten cache-eli).
 * audit() naplóbejegyzést ír.
 */
class HrPermission
{
  /** Szabályozható statikus mezők, szekció szerinti csoportosításban */
  public const STATIC_FIELDS = [
    'Személyes adatok' => [
      'birth_name'  => 'Születési név',
      'mother_name' => 'Anyja neve',
      'birth_place' => 'Születési hely',
      'birth_date'  => 'Születési dátum',
    ],
    'Céges / azonosító' => [
      'company_emp_no' => 'Céges törzsszám',
      'division_name'  => 'Divízió',
      'tax_id'         => 'Adóazonosító',
      'taj'            => 'TAJ szám',
    ],
    'Bankszámla' => [
      'bank_account' => 'Bankszámlaszám',
      'bank_name'    => 'Bank neve',
    ],
    'Munkaviszony' => [
      'hired_on'  => 'Belépés dátuma',
      'left_on'   => 'Kilépés dátuma',
      'is_active' => 'Állapot',
    ],
    'Lakcím' => [
      'addr_zip'  => 'Irányítószám',
      'addr_city' => 'Település',
      'addr_line' => 'Cím',
    ],
    'Kapcsolat' => [
      'email'                => 'Email (céges)',
      'email_private'        => 'Email (privát)',
      'payslip_email_target' => 'Bérjegyzék email',
      'phone'                => 'Telefon',
      'notes'                => 'Megjegyzés',
    ],
  ];

  private static bool  $loaded = false;
  private static ?array $cache = null;

  /**
   * Betölti az adott user HR jogosultság rekordját.
   * Null = nincs rekord → nincs hozzáférés.
   * Visszaad: ['perm_id'=>int, 'divisions'=>[int,...], 'fields'=>[str,...], 'extra_fields'=>[int,...]]
   */
  public static function load(Db $db, int $userId): ?array
  {
    if (self::$loaded) return self::$cache;
    self::$loaded = true;

    $pdo  = $db->pdo();
    $stmt = $pdo->prepare("SELECT id FROM hr_permissions WHERE user_id = :uid LIMIT 1");
    $stmt->execute(['uid' => $userId]);
    $rec  = $stmt->fetch();

    if (!$rec) {
      self::$cache = null;
      return null;
    }

    $permId = (int)$rec['id'];

    $r1 = $pdo->prepare("SELECT division_id   FROM hr_perm_divisions    WHERE perm_id = :pid");
    $r2 = $pdo->prepare("SELECT field_key     FROM hr_perm_fields       WHERE perm_id = :pid");
    $r3 = $pdo->prepare("SELECT extra_field_id FROM hr_perm_extra_fields WHERE perm_id = :pid");

    $r1->execute(['pid' => $permId]);
    $r2->execute(['pid' => $permId]);
    $r3->execute(['pid' => $permId]);

    self::$cache = [
      'perm_id'      => $permId,
      'divisions'    => array_map('intval', array_column($r1->fetchAll(), 'division_id')),
      'fields'       => array_column($r2->fetchAll(), 'field_key'),
      'extra_fields' => array_map('intval', array_column($r3->fetchAll(), 'extra_field_id')),
    ];
    return self::$cache;
  }

  /**
   * Visszaad egy closure-t: canSee(string $fieldKey): bool
   * Admin esetén minden látható (null perm), user esetén whitelist + full_name mindig true.
   */
  public static function fieldChecker(?array $perm): \Closure
  {
    if ($perm === null) {
      // admin: mindent lát
      return fn(string $key): bool => true;
    }
    $allowed = $perm['fields'];
    return fn(string $key): bool => ($key === 'full_name') || in_array($key, $allowed, true);
  }

  /**
   * Visszaad egy closure-t: canSeeExtra(int $extraFieldId): bool
   */
  public static function extraFieldChecker(?array $perm): \Closure
  {
    if ($perm === null) {
      return fn(int $id): bool => true;
    }
    $allowed = $perm['extra_fields'];
    return fn(int $id): bool => in_array($id, $allowed, true);
  }

  /**
   * Audit napló bejegyzés írása.
   */
  public static function audit(
    Db      $db,
    int     $userId,
    string  $userName,
    string  $action,
    int     $employeeId,
    ?string $fieldKey  = null,
    ?string $oldValue  = null,
    ?string $newValue  = null,
    ?string $detail    = null
  ): void {
    try {
      $db->pdo()->prepare("
        INSERT INTO hr_audit_log
          (user_id, user_name, action, employee_id, field_key, old_value, new_value, detail)
        VALUES
          (:uid, :uname, :action, :eid, :fkey, :old, :new, :detail)
      ")->execute([
        'uid'    => $userId,
        'uname'  => $userName,
        'action' => $action,
        'eid'    => $employeeId,
        'fkey'   => $fieldKey,
        'old'    => $oldValue,
        'new'    => $newValue,
        'detail' => $detail,
      ]);
    } catch (PDOException $e) {
      error_log('[HR audit] ' . $e->getMessage());
    }
  }
}
