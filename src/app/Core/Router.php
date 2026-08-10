<?php
declare(strict_types=1);

namespace App\Core;

class Router
{
    private array $routes = [];
    private array $groupMiddleware = [];
    private string $prefix = '';

    public function group(string $prefix, callable $callback, array $middleware = []): void
    {
        $prevPrefix = $this->prefix;
        $prevMiddleware = $this->groupMiddleware;
        $this->prefix .= $prefix;
        $this->groupMiddleware = array_merge($this->groupMiddleware, $middleware);
        $callback($this);
        $this->prefix = $prevPrefix;
        $this->groupMiddleware = $prevMiddleware;
    }

    public function get(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $handler, $middleware);
    }

    public function put(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('PUT', $path, $handler, $middleware);
    }

    public function delete(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    public function any(string $path, callable|array $handler, array $middleware = []): void
    {
        foreach (['GET', 'POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
            $this->addRoute($method, $path, $handler, $middleware);
        }
    }

    private function addRoute(string $method, string $path, callable|array $handler, array $middleware): void
    {
        $path = $this->prefix . '/' . ltrim($path, '/');
        $path = '/' . trim($path, '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');

        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
            'pattern' => $this->compilePattern($path),
        ];
    }

    private function compilePattern(string $path): string
    {
        $pattern = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $path);
        return '#^' . $pattern . '$#';
    }

    public function dispatch(string $method, string $uri): mixed
    {
        $uri = '/' . trim(parse_url($uri, PHP_URL_PATH) ?: '', '/');
        $uri = $uri === '/' ? '/' : rtrim($uri, '/');

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method && $route['method'] !== 'ANY') {
                continue;
            }

            if (preg_match($route['pattern'], $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                // Execute middleware chain
                $handler = $route['handler'];
                foreach (array_reverse($route['middleware']) as $mwClass) {
                    $next = $handler;
                    $handler = function (Request $request) use ($mwClass, $next) {
                        $mw = new $mwClass();
                        // Wrap the inner handler so the middleware invokes it
                        // through invoke() (which instantiates array handlers).
                        return $mw->handle($request, fn($req) => $this->invoke($next, $req));
                    };
                }

                $request = new Request($method, $uri, $params, $_GET, $_POST, getallheaders() ?: [], file_get_contents('php://input') ?: '');
                return $this->invoke($handler, $request);
            }
        }

        // 404
        http_response_code(404);
        $response = new Response();
        return $response->json(['error' => 'Not Found', 'message' => 'The requested route does not exist.'], 404);
    }

    /**
     * Resolve a route handler and invoke it with the request.
     *
     * Route handlers are registered as arrays [ControllerClass, 'action'].
     * Controller action methods are non-static instance methods, so a
     * class-string entry must be instantiated before being called. Calling
     * an array callable directly ($handler($request)) resolves to a static
     * call and throws "Non-static method ... cannot be called statically".
     *
     * Also handles an already-instantiated object [instance, 'action'] and
     * plain callables (closures from the middleware chain).
     */
    private function invoke(mixed $handler, Request $request): mixed
    {
        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            if (is_object($class)) {
                return $class->$method($request);
            }
            $instance = new $class();
            return $instance->$method($request);
        }

        return $handler($request);
    }
}
