<?php
function fetch_categories_tree(PDO $pdo): array {
  $rows = $pdo->query("SELECT id,name,parent_id,sort_order FROM categories WHERE is_deleted=0 ORDER BY sort_order, name")->fetchAll();
  $byParent = [];
  foreach ($rows as $r) {
    $byParent[(int)($r['parent_id'] ?? 0)][] = $r;
  }
  $out=[];
  $walk=function($parent, $depth) use (&$walk, &$out, $byParent){
    foreach (($byParent[$parent] ?? []) as $r){
      $r['_depth']=$depth;
      $out[]=$r;
      $walk((int)$r['id'], $depth+1);
    }
  };
  $walk(0,0);
  return $out;
}
