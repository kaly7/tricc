<?php
require_once __DIR__ . '/theme.php';
$t = current_theme();
$css = theme_css_href($t);
?><!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="main.css">
  <link rel="stylesheet" href="<?=$css?>">
</head>
<body>
  <div class="topbar">
    <div class="container">
      <strong>Dokutool</strong>
      <span style="float:right;">
        <form method="post" action="set_theme.php" style="display:inline;">
          <select name="theme" onchange="this.form.submit()">
            <option value="dark" <?=$t==='dark'?'selected':''?>>Sötét</option>
            <option value="light" <?=$t==='light'?'selected':''?>>Világos</option>
            <option value="modern" <?=$t==='modern'?'selected':''?>>Modern</option>
            <option value="industrial" <?=$t==='industrial'?'selected':''?>>Indusztriál</option>
          </select>
        </form>
      </span>
    </div>
  </div>
