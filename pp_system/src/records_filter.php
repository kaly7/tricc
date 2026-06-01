<?php
function build_records_filter(&$params, $src = null, $extraWhere = []) {





// ===== DUE gyorsszűrő (overrides) =====
$due = $A['due'] ?? null;
if (in_array($due, ['overdue','today2','next10'], true)) {
    // dátumok
    $tz     = new DateTimeZone('Europe/Budapest');
    $today  = new DateTime('today', $tz);
    $plus2  = (clone $today)->modify('+2 days');   // ma..+2
    $plus3  = (clone $today)->modify('+3 days');   // következő ablak kezdete
    $plus12 = (clone $today)->modify('+12 days');  // következő ablak vége

    $fmt       = 'Y-m-d';
    $todayStr  = $today->format($fmt);
    $plus2Str  = $plus2->format($fmt);
    $plus3Str  = $plus3->format($fmt);
    $plus12Str = $plus12->format($fmt);

    // teljes felülírás: csak aktív, nem törölt + dátumtartomány
    $w   = ['r.deleted_at IS NULL', 'r.archived=0'];
    $par = [];

    if ($due === 'overdue') {
        $w[]   = 'r.due_at < ?';
        $par[] = $todayStr;
        $order = 'r.due_at ASC';
    } elseif ($due === 'today2') {
        $w[]   = 'r.due_at BETWEEN ? AND ?';
        $par[] = $todayStr; $par[] = $plus2Str;
        $order = 'r.due_at ASC';
    } else { // next10
        $w[]   = 'r.due_at BETWEEN ? AND ?';
        $par[] = $plus3Str; $par[] = $plus12Str;
        $order = 'r.due_at ASC';
    }

    return [
        'where' => implode(' AND ', $w),
        'params'=> $par,
        // ha külön rendezést kaptál kívülről, ezt felül lehet írni,
        // de a gyorsszűrő logikusan due_at szerint rendez.
        'order' => $order,
        // segítség a fejléchez
        'meta'  => [
            'due'       => $due,
            'today'     => $todayStr,
            'plus2'     => $plus2Str,
            'plus3'     => $plus3Str,
            'plus12'    => $plus12Str,
        ],
    ];
}









  $A = $src ?? $_GET;

  $search    = trim($A['q'] ?? '');
  $arch_mode = $A['arch'] ?? 'no';
  $sort      = $A['sort'] ?? 'issued_at';

  // --- PP státusz szűrők (kétféle név támogatása) ---
  // Preferált: pp_inc / pp_exc; Ha nincs, akkor pp_status_id[] + pp_mode (include/exclude)
  $pp_inc = $A['pp_inc'] ?? null;
  $pp_exc = $A['pp_exc'] ?? null;
  if ($pp_inc === null && $pp_exc === null) {
    $ids = $A['pp_status_id'] ?? [];
    $mode = $A['pp_mode'] ?? 'include'; // include | exclude
    if ($mode === 'exclude') {
      $pp_exc = $ids;
      $pp_inc = [];
    } else {
      $pp_inc = $ids;
      $pp_exc = [];
    }
  }

  // --- Város szűrők (kétféle név támogatása) ---
  $city_inc = $A['city_inc'] ?? null;
  $city_exc = $A['city_exc'] ?? null;
  if ($city_inc === null && $city_exc === null) {
    $cids = $A['city_id'] ?? [];
    $cmode = $A['city_mode'] ?? 'include'; // include | exclude
    if ($cmode === 'exclude') {
      $city_exc = $cids;
      $city_inc = [];
    } else {
      $city_inc = $cids;
      $city_exc = [];
    }
  }

  // Rendezés
  $allowedSort = ['issued_at','due_at','pp_status','eventus','city'];
  if (!in_array($sort, $allowedSort, true)) $sort = 'issued_at';
  $desc = !empty($A['desc']);
  $dir  = $desc ? 'DESC' : 'ASC';

  // WHERE felépítés
  $params = [];
  //$where  = ['r.deleted_at IS NULL'];
  //if ($arch_mode !== 'yes') $where[] = 'r.archived = 0';

    // records_filter.php – részlet
    $include_arch = !empty($A['include_arch']); // <= EZ A LÉNYEG

    $where = ['r.deleted_at IS NULL'];
    if (!$include_arch) {
	$where[] = 'r.archived = 0';
    }

  if ($search !== '') {
    $like = '%'.$search.'%';
    $where[] = '(r.eventus LIKE ? OR r.address LIKE ? OR r.operation LIKE ? OR r.long_desc LIKE ?)';
    array_push($params, $like, $like, $like, $like);
  }

  $ints = fn($arr) => array_values(array_filter(array_map('intval', (array)$arr), fn($v)=>$v>0));
  $ppInc   = $ints($pp_inc);
  $ppExc   = $ints($pp_exc);
  $cityInc = $ints($city_inc);
  $cityExc = $ints($city_exc);

  if ($ppInc)   { $where[] = 'r.pp_status_id IN ('.implode(',', array_fill(0, count($ppInc), '?')).')';   $params = array_merge($params, $ppInc); }
  if ($ppExc)   { $where[] = 'r.pp_status_id NOT IN ('.implode(',', array_fill(0, count($ppExc), '?')).')'; $params = array_merge($params, $ppExc); }
  if ($cityInc) { $where[] = 'r.city_id IN ('.implode(',', array_fill(0, count($cityInc), '?')).')';        $params = array_merge($params, $cityInc); }
  if ($cityExc) { $where[] = 'r.city_id NOT IN ('.implode(',', array_fill(0, count($cityExc), '?')).')';    $params = array_merge($params, $cityExc); }

  if ($extraWhere) $where = array_merge($where, $extraWhere);

  $orderBy = ($sort === 'pp_status') ? 'ps.name'
           : (($sort === 'city')     ? 'c.name'
           : (in_array($sort, ['eventus','issued_at','due_at']) ? 'r.'.$sort : 'r.issued_at'));











  return ['where' => implode(' AND ', $where), 'order' => "$orderBy $dir"];
}