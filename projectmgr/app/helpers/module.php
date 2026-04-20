<?php
namespace App\Helpers;

use App\Auth;

class ModuleContext {
  public static function key(): string {
    return Auth::currentModuleKey();
  }

  public static function qs(): string {
    return self::key()==='vehicles' ? '?module=vehicles' : '';
  }

  public static function url(string $path): string {
    return $path . self::qs();
  }
}
