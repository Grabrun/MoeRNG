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

    public function delete(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('DELETE', $path, $handler, $middleware);
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

        // HEAD 按 GET 路由，避免健康检查/扫描器探测时 404 并污染监控告警。
        if ($method === 'HEAD') {
            $method = 'GET';
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method && $route['method'] !== 'ANY') {
                continue;
            }

            if (preg_match($route['pattern'], $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                $handler = $route['handler'];
                foreach (array_reverse($route['middleware']) as $mwClass) {
                    $next = $handler;
                    $handler = function (Request $request) use ($mwClass, $next) {
                        $mw = new $mwClass();
                        return $mw->handle($request, fn($req) => $this->invoke($next, $req));
                    };
                }

                // 不读 php://input：没有调用方消费请求体，避免 GET 也白读一次。
                $request = new Request($method, $uri, $params, $_GET, $_POST, getallheaders() ?: []);
                return $this->invoke($handler, $request);
            }
        }

        http_response_code(404);
        $response = new Response();
        return $response->json(['success' => false, 'error' => 'Not Found', 'message' => 'The requested route does not exist.'], 404);
    }

    /** 处理器为 [ControllerClass, 'action'] 时须实例化后再调用，直接调用会被当作静态方法而报错。 */
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
