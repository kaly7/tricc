<?php
function current_theme(){
  $allowed = ['light','dark','modern','industrial'];
  $t = $_COOKIE['theme'] ?? 'dark';
  if(!in_array($t, $allowed, true)) $t = 'dark';
  return $t;
}
function theme_css_href($theme){
  switch($theme){
    case 'light': return 'theme-light.css';
    case 'modern': return 'theme-modern.css';
    case 'industrial': return 'theme-industrial.css';
    case 'dark':
    default: return 'theme-dark.css';
  }
}
