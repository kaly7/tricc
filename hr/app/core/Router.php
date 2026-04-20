<?php
class Router
{
  private array $routes = ['GET' => [], 'POST' => []];

  public function get(string $path, callable|array $handler): void
  {
    $this->routes['GET'][$path] = $handler;
  }

  public function post(string $path, callable|array $handler): void
  {
    $this->routes['POST'][$path] = $handler;
  }

  public function dispatch(): void
  {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';

    // strip query string
    $uri = explode('?', $uri, 2)[0];

    $handler = $this->routes[$method][$uri] ?? null;

    if (!$handler) {
      http_response_code(404);
      echo "404 - Not Found";
      return;
    }

    if (is_array($handler)) {
      [$obj, $methodName] = $handler;
      $obj->$methodName();
      return;
    }

    $handler();
  }
}