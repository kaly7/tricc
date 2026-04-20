<?php
require_once __DIR__.'/../src/mailer.php';

try {
  $m = make_mailer();
  $m->addAddress('janos@kalamar.hu');
  $m->Subject = 'SMTP teszt';
  $m->Body    = "Hello, ez egy SMTP próba.";
  $m->send();
  echo "OK: elment.";
} catch (Throwable $e) {
  echo "HIBA: ".$e->getMessage();
}
