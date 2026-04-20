<?php
class View
{
  public function render(string $view, array $data = []): void
  {
    extract($data, EXTR_SKIP);
    require APP_ROOT . '/app/views/' . $view . '.php';
  }
}