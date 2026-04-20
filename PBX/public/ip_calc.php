<?php
require __DIR__.'/../app/auth.php';
require_login();

$title = 'IP kalkulátor';
$page  = 'IP kalkulátor';
require __DIR__.'/_header.php';

function ipv4_mask_to_cidr(int $mask_long): int {
  $mask_long = $mask_long & 0xFFFFFFFF;
  $cidr = 0;
  for ($i=31; $i>=0; $i--) {
    if (($mask_long >> $i) & 1) $cidr++;
    else break;
  }
  return $cidr;
}

function ipv4_calc(string $ip, string $mask): ?array {
  $ip_long = ip2long($ip);
  if ($ip_long === false) return null;

  $cidr = null;
  if (str_starts_with($mask, '/')) {
    $cidr = (int)substr($mask, 1);
  } elseif (ctype_digit($mask)) {
    $cidr = (int)$mask;
  }

  if ($cidr !== null) {
    if ($cidr < 0 || $cidr > 32) return null;
    $mask_long = $cidr === 0 ? 0 : ((-1 << (32-$cidr)) & 0xFFFFFFFF);
  } else {
    $mask_long = ip2long($mask);
    if ($mask_long === false) return null;
    $mask_long = $mask_long & 0xFFFFFFFF;
    $cidr = ipv4_mask_to_cidr($mask_long);
  }

  $network   = ($ip_long & $mask_long) & 0xFFFFFFFF;
  $broadcast = ($network | (~$mask_long & 0xFFFFFFFF)) & 0xFFFFFFFF;

  $hostBits = 32 - $cidr;
  $total = ($hostBits >= 31) ? (1 << 31) * 2 : (1 << $hostBits);

  // usable range
  $usable = 0;
  $first = null;
  $last  = null;

  if ($cidr === 32) {
    $usable = 1;
    $first = long2ip($network);
    $last  = long2ip($network);
  } elseif ($cidr === 31) {
    $usable = 2;
    $first = long2ip($network);
    $last  = long2ip($broadcast);
  } else {
    $usable = max(0, ($broadcast - $network - 1));
    $first = long2ip($network + 1);
    $last  = long2ip($broadcast - 1);
  }

  $wildcard = (~$mask_long) & 0xFFFFFFFF;

  return [
    'ip'        => $ip,
    'cidr'      => $cidr,
    'mask'      => long2ip($mask_long),
    'wildcard'  => long2ip($wildcard),
    'network'   => long2ip($network),
    'broadcast' => long2ip($broadcast),
    'first'     => $first,
    'last'      => $last,
    'usable'    => $usable,
    'total'     => $total,
  ];
}

function ipv6_make_mask(int $prefix): string {
  $prefix = max(0, min(128, $prefix));
  $bytes = '';
  $rem = $prefix;
  for ($i=0; $i<16; $i++) {
    if ($rem >= 8) { $bytes .= chr(0xFF); $rem -= 8; }
    elseif ($rem > 0) { $bytes .= chr((0xFF << (8-$rem)) & 0xFF); $rem = 0; }
    else { $bytes .= chr(0x00); }
  }
  return $bytes;
}

function ipv6_calc(string $ip, string $prefixStr): ?array {
  $ip_bin = @inet_pton($ip);
  if ($ip_bin === false || strlen($ip_bin) !== 16) return null;

  $prefixStr = trim($prefixStr);
  if (str_starts_with($prefixStr, '/')) $prefixStr = substr($prefixStr, 1);
  if (!ctype_digit($prefixStr)) return null;
  $prefix = (int)$prefixStr;
  if ($prefix < 0 || $prefix > 128) return null;

  $mask = ipv6_make_mask($prefix);
  $network = $ip_bin & $mask;
  $inv = $mask ^ str_repeat("\xFF", 16);
  $last = $network | $inv;

  $hostBits = 128 - $prefix;
  $totalStr = ($hostBits <= 62) ? (string)(1 << $hostBits) : ('2^'.$hostBits);

  return [
    'ip'      => $ip,
    'prefix'  => $prefix,
    'network' => inet_ntop($network),
    'first'   => inet_ntop($network),
    'last'    => inet_ntop($last),
    'total'   => $totalStr,
  ];
}

$mode = (string)($_POST['mode'] ?? 'ipv4');

$res4 = null;
$res6 = null;
$err4 = '';
$err6 = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if ($mode === 'ipv6') {
    $ip6 = trim((string)($_POST['ip6'] ?? ''));
    $pfx = trim((string)($_POST['pfx'] ?? ''));
    if ($ip6==='' || $pfx==='') $err6 = 'IPv6 cím és prefix megadása kötelező.';
    else {
      $res6 = ipv6_calc($ip6, $pfx);
      if (!$res6) $err6 = 'Érvénytelen IPv6 cím vagy prefix.';
    }
  } else {
    $ip = trim((string)($_POST['ip'] ?? ''));
    $mask = trim((string)($_POST['mask'] ?? ''));
    if ($ip==='' || $mask==='') $err4 = 'IP és maszk megadása kötelező.';
    else {
      $res4 = ipv4_calc($ip, $mask);
      if (!$res4) $err4 = 'Érvénytelen IP vagy maszk.';
    }
  }
}
?>

<h1 class="h3 mb-3">IP kalkulátor</h1>

<ul class="nav nav-tabs mb-3" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $mode!=='ipv6'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#t4" type="button" role="tab">IPv4</button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $mode==='ipv6'?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#t6" type="button" role="tab">IPv6</button>
  </li>
</ul>

<div class="tab-content">
  <div class="tab-pane fade <?= $mode!=='ipv6'?'show active':'' ?>" id="t4" role="tabpanel">
    <div class="card p-3 mb-3">
      <form method="post" class="row g-3">
        <input type="hidden" name="mode" value="ipv4">
        <div class="col-md-4">
          <label class="form-label">IPv4 cím</label>
          <input class="form-control" name="ip" placeholder="192.168.1.10" value="<?= e($_POST['ip'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Maszk (CIDR vagy dotted)</label>
          <input class="form-control" name="mask" placeholder="/24 vagy 255.255.255.0 vagy 24" value="<?= e($_POST['mask'] ?? '') ?>">
        </div>
        <div class="col-md-4 d-flex align-items-end">
          <button class="btn btn-primary w-100">Számol</button>
        </div>
      </form>
    </div>

    <?php if ($err4): ?>
      <div class="alert alert-danger"><?= e($err4) ?></div>
    <?php endif; ?>

    <?php if ($res4): ?>
    <div class="card">
      <table class="table mb-0">
        <tr><th style="width:240px">CIDR</th><td>/<?= (int)$res4['cidr'] ?></td></tr>
        <tr><th>Maszk</th><td><?= e($res4['mask']) ?></td></tr>
        <tr><th>Wildcard</th><td><?= e($res4['wildcard']) ?></td></tr>
        <tr><th>Hálózat</th><td><?= e($res4['network']) ?></td></tr>
        <tr><th>Broadcast</th><td><?= e($res4['broadcast']) ?></td></tr>
        <tr><th>Első / utolsó host</th><td><?= e($res4['first']) ?> — <?= e($res4['last']) ?></td></tr>
        <tr><th>Használható hostok</th><td><?= e((string)$res4['usable']) ?></td></tr>
        <tr><th>Összes cím a subnetben</th><td><?= e((string)$res4['total']) ?></td></tr>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="tab-pane fade <?= $mode==='ipv6'?'show active':'' ?>" id="t6" role="tabpanel">
    <div class="card p-3 mb-3">
      <form method="post" class="row g-3">
        <input type="hidden" name="mode" value="ipv6">
        <div class="col-md-6">
          <label class="form-label">IPv6 cím</label>
          <input class="form-control" name="ip6" placeholder="2001:db8::1" value="<?= e($_POST['ip6'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Prefix</label>
          <input class="form-control" name="pfx" placeholder="/64 vagy 64" value="<?= e($_POST['pfx'] ?? '') ?>">
        </div>
        <div class="col-md-3 d-flex align-items-end">
          <button class="btn btn-primary w-100">Számol</button>
        </div>
      </form>
    </div>

    <?php if ($err6): ?>
      <div class="alert alert-danger"><?= e($err6) ?></div>
    <?php endif; ?>

    <?php if ($res6): ?>
    <div class="card">
      <table class="table mb-0">
        <tr><th style="width:240px">Prefix</th><td>/<?= (int)$res6['prefix'] ?></td></tr>
        <tr><th>Hálózat</th><td><?= e($res6['network']) ?></td></tr>
        <tr><th>Első cím</th><td><?= e($res6['first']) ?></td></tr>
        <tr><th>Utolsó cím</th><td><?= e($res6['last']) ?></td></tr>
        <tr><th>Összes cím a prefixben</th><td><?= e($res6['total']) ?></td></tr>
      </table>
      <div class="p-3 text-muted small">
        Megjegyzés: IPv6-nál az “Összes cím” nagy prefixeknél 2^N formában jelenhet meg.
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__.'/_footer.php'; ?>
