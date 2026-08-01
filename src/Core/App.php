<?php
declare(strict_types=1);

namespace App\Core;

use App\Http\Exceptions\HttpException;
use App\Http\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Http\Session;
use App\Middleware\MiddlewareInterface;
use Throwable;

final class App
{
    private static ?self $instance = null;

    private Router $router;
    private array $middlewareAliases = [];
    private array $globalMiddleware = [];
    private ?Request $request = null;
    private float $startedAt;

    private function __construct(private string $basePath)
    {
        $this->startedAt = microtime(true);
        $this->router = new Router();
    }

    public static function boot(string $basePath): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $app = new self(rtrim($basePath, '/\\'));
        self::$instance = $app;

        Env::load($app->basePath . '/.env');
        Config::loadPath($app->basePath . '/config');

        date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));

        Logger::setPath($app->basePath . '/storage/logs');
        View::setPath($app->basePath . '/resources/views');

        $app->configureErrorHandling();
        $app->registerMiddleware();

        return $app;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Application has not been booted.');
        }

        return self::$instance;
    }

    public function basePath(string $append = ''): string
    {
        return $this->basePath . ($append !== '' ? '/' . ltrim($append, '/') : '');
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function request(): ?Request
    {
        return $this->request;
    }

    public function elapsedMs(): float
    {
        return round((microtime(true) - $this->startedAt) * 1000, 2);
    }

    private function configureErrorHandling(): void
    {
        $debug = (bool) Config::get('app.debug', false);

        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                Logger::critical($error['message'], ['file' => $error['file'], 'line' => $error['line']]);
            }
        });
    }

    private function registerMiddleware(): void
    {
        $this->middlewareAliases = [
            'security'   => \App\Middleware\SecurityHeaders::class,
            'https'      => \App\Middleware\ForceHttps::class,
            'cors'       => \App\Middleware\Cors::class,
            'csrf'       => \App\Middleware\VerifyCsrf::class,
            'auth'       => \App\Middleware\Authenticate::class,
            'guest'      => \App\Middleware\RedirectIfAuthenticated::class,
            'admin'      => \App\Middleware\RequireAdmin::class,
            'api'        => \App\Middleware\ApiAuthenticate::class,
            'api.scope'  => \App\Middleware\RequireScope::class,
            'throttle'   => \App\Middleware\RateLimit::class,
            'ip'         => \App\Middleware\IpFilter::class,
            'idempotent' => \App\Middleware\Idempotency::class,
            'installed'  => \App\Middleware\EnsureInstalled::class,
            'audit'      => \App\Middleware\AuditWrites::class,
        ];

        $this->globalMiddleware = ['security', 'https', 'ip'];
    }

    public function run(): void
    {
        $request = Request::capture();
        $this->request = $request;

        $response = $this->handle($request);
        $response->send();
    }

    public function handle(Request $request): Response
    {
        try {
            if (!$request->isApi()) {
                Session::start();
            }

            $this->loadRoutes();

            $match = $this->router->match($request->method, $request->path);

            foreach ($match['params'] as $key => $value) {
                $request->setAttribute('route.' . $key, $value);
            }
            $request->setAttribute('route.params', $match['params']);

            $middleware = array_merge($this->globalMiddleware, $match['middleware']);

            $pipeline = $this->buildPipeline(
                $middleware,
                fn (Request $req): Response => $this->callHandler($match['handler'], $req, $match['params'])
            );

            return $pipeline($request);
        } catch (Throwable $e) {
            return $this->renderException($request, $e);
        }
    }

    private function loadRoutes(): void
    {
        static $loaded = false;

        if ($loaded) {
            return;
        }

        $loaded = true;
        $router = $this->router;

        require $this->basePath . '/routes/web.php';
        require $this->basePath . '/routes/api.php';
    }

    private function buildPipeline(array $middleware, callable $destination): callable
    {
        $pipeline = $destination;

        foreach (array_reverse($middleware) as $name) {
            $pipeline = function (Request $request) use ($name, $pipeline): Response {
                [$alias, $param] = array_pad(explode(':', $name, 2), 2, null);

                $class = $this->middlewareAliases[$alias] ?? $alias;

                if (!class_exists($class)) {
                    throw new \RuntimeException("Middleware not found: {$alias}");
                }

                /** @var MiddlewareInterface $instance */
                $instance = new $class();

                return $instance->handle($request, $pipeline, $param === null ? [] : explode(',', $param));
            };
        }

        return $pipeline;
    }

    private function callHandler(mixed $handler, Request $request, array $params): Response
    {
        if (is_callable($handler)) {
            $result = $handler($request, ...array_values($params));
        } elseif (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);

            if (!class_exists($class)) {
                throw new \RuntimeException("Controller not found: {$class}");
            }

            $controller = new $class();

            if (!method_exists($controller, $method)) {
                throw new \RuntimeException("Method not found: {$class}::{$method}");
            }

            $result = $controller->{$method}($request, ...array_values($params));
        } else {
            throw new \RuntimeException('Invalid route handler.');
        }

        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result) || is_object($result)) {
            return Response::json($result);
        }

        return Response::html((string) $result);
    }

    private function renderException(Request $request, Throwable $e): Response
    {
        $status = $e instanceof HttpException ? $e->status() : 500;
        $code = $e instanceof HttpException ? $e->errorCode() : 'server_error';
        $details = $e instanceof HttpException ? $e->details() : [];
        $debug = (bool) Config::get('app.debug', false);

        if ($status >= 500) {
            Logger::exception($e, ['path' => $request->path, 'method' => $request->method]);
        } elseif ($status !== 404) {
            Logger::warning($e->getMessage(), ['path' => $request->path, 'status' => $status]);
        }

        $message = $status >= 500 && !$debug
            ? 'An unexpected error occurred.'
            : $e->getMessage();

        if ($request->wantsJson()) {
            $payload = ['code' => $code, 'message' => $message];

            if ($e instanceof ValidationException) {
                $payload['details'] = $e->errors();
            } elseif ($details !== []) {
                $payload['details'] = $details;
            }

            if ($debug && $status >= 500) {
                $payload['debug'] = [
                    'exception' => $e::class,
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                    'trace'     => array_slice(explode("\n", $e->getTraceAsString()), 0, 15),
                ];
            }

            return Response::json(['success' => false, 'error' => $payload], $status);
        }

        if ($e instanceof ValidationException) {
            Session::flashErrors($e->errors());
            Session::flashInput($request->all());
            $referer = $request->header('Referer') ?? url('/');

            return Response::redirect($referer);
        }

        try {
            $html = View::render('errors.error', [
                'status'  => $status,
                'message' => $message,
                'debug'   => $debug ? $e : null,
                'title'   => match (true) {
                    $code === 'csrf_mismatch' => 'Session expired',
                    $status === 403 => 'Access denied',
                    $status === 404 => 'Page not found',
                    $status === 405 => 'Method not allowed',
                    $status === 429 => 'Too many requests',
                    default         => 'Something went wrong',
                },
            ]);
        } catch (Throwable) {
            $html = '<h1>' . $status . '</h1><p>' . e($message) . '</p>';
        }

        return Response::html($html, $status);
    }
}
