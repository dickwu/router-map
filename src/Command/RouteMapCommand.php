<?php

declare(strict_types=1);
/**
 * This file is part of Green Wood Staff Chat.
 */

namespace HyperfAi\RouterMap\Command;

use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpServer\MiddlewareManager;
use Hyperf\HttpServer\Router\DispatcherFactory;
use Hyperf\HttpServer\Router\Handler;
use Hyperf\HttpServer\Router\RouteCollector;
use Hyperf\Stringable\Str;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Output\BufferedOutput;

class RouteMapCommand extends HyperfCommand
{
    protected ?string $signature = 'route:map
        {--server=http : Which server to inspect}
        {--path= : Optional substring filter for route URI}
        {--since-commit= : Optional baseline commit-ish; when provided, only APIs whose related files changed since that commit are returned}
        {--output-dir=runtime/route-maps : Output directory for raw/json/summary artifacts}
        {--stamp= : Optional filename stamp; defaults to Ymd_His_<short-commit>}
        {--format=json : Stdout format: json, summary, or table}';

    protected string $description = 'Export Hyperf route map using the same analysis path as describe:routes and write raw/json/summary artifacts; default stdout is JSON for LLM workflows';

    private array $fileCommitCache = [];

    public function __construct(
        protected ContainerInterface $container,
        protected ConfigInterface $config,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $server = (string) $this->input->getOption('server');
        $path = trim((string) $this->input->getOption('path'));
        $path = $path === '' ? null : $path;
        $outputDir = trim((string) $this->input->getOption('output-dir'));
        $outputDir = $outputDir === '' ? 'runtime/route-maps' : $outputDir;
        $sinceCommit = trim((string) $this->input->getOption('since-commit'));
        $sinceCommit = $sinceCommit === '' ? null : $sinceCommit;
        $format = strtolower(trim((string) $this->input->getOption('format')));
        $format = $format === '' ? 'json' : $format;
        if (! in_array($format, ['json', 'summary', 'table'], true)) {
            $this->error('Invalid --format value. Use json, summary, or table.');
            return 1;
        }

        $shortCommit = $this->git('rev-parse --short HEAD');
        $stamp = trim((string) $this->input->getOption('stamp'));
        $stamp = $stamp === '' ? sprintf('%s_%s', date('Ymd_His'), $shortCommit) : $stamp;

        $absoluteOutputDir = $this->toAbsolutePath($outputDir);
        if (! is_dir($absoluteOutputDir)) {
            mkdir($absoluteOutputDir, 0755, true);
        }

        $factory = $this->container->get(DispatcherFactory::class);
        $router = $factory->getRouter($server);
        $allRoutes = $this->analyzeRouter($server, $router, $path);
        $changedFiles = $this->resolveChangedFiles($sinceCommit);
        $routes = $this->filterRoutesByChangedFiles($allRoutes, $changedFiles, $sinceCommit !== null);

        $tableOutput = $this->renderTable($routes);
        $summary = $this->summarizeByPrefix($routes);
        $payload = $this->buildPayload($server, $path, $stamp, $routes, $summary, $allRoutes, $sinceCommit, $changedFiles);
        $summaryOutput = $this->renderSummary($payload);
        $jsonOutput = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        $rawFile = sprintf('%s/routes_%s.txt', $absoluteOutputDir, $stamp);
        $jsonFile = sprintf('%s/routes_%s.json', $absoluteOutputDir, $stamp);
        $summaryFile = sprintf('%s/routes_%s_summary.txt', $absoluteOutputDir, $stamp);

        file_put_contents($rawFile, $tableOutput);
        file_put_contents($jsonFile, $jsonOutput);
        file_put_contents($summaryFile, $summaryOutput);

        match ($format) {
            'table' => $this->output->write($tableOutput),
            'summary' => $this->output->write($summaryOutput),
            default => $this->output->write($jsonOutput),
        };

        fwrite(STDERR, sprintf("route_map_raw_file=%s\n", $rawFile));
        fwrite(STDERR, sprintf("route_map_json_file=%s\n", $jsonFile));
        fwrite(STDERR, sprintf("route_map_summary_file=%s\n", $summaryFile));

        return 0;
    }

    protected function analyzeRouter(string $server, RouteCollector $router, ?string $path): array
    {
        $data = [];
        [$staticRouters, $variableRouters] = $router->getData();

        foreach ($staticRouters as $method => $items) {
            foreach ($items as $handler) {
                $this->analyzeHandler($data, $server, $method, $path, $handler);
            }
        }

        foreach ($variableRouters as $method => $items) {
            foreach ($items as $item) {
                if (is_array($item['routeMap'] ?? false)) {
                    foreach ($item['routeMap'] as $routeMap) {
                        $this->analyzeHandler($data, $server, $method, $path, $routeMap[0]);
                    }
                }
            }
        }

        ksort($data);
        return array_values($data);
    }

    protected function analyzeHandler(array &$data, string $serverName, string $method, ?string $path, Handler $handler): void
    {
        $uri = $handler->route;
        if ($path !== null && ! Str::contains($uri, $path)) {
            return;
        }

        if (is_array($handler->callback)) {
            $action = $handler->callback[0] . '::' . $handler->callback[1];
        } elseif (is_string($handler->callback)) {
            $action = $handler->callback;
        } else {
            $action = 'Closure';
        }

        $unique = sprintf('%s|%s|%s', $serverName, $uri, $action);
        if (isset($data[$unique])) {
            $data[$unique]['methods'][] = $method;
            return;
        }

        $registeredMiddlewares = MiddlewareManager::get($serverName, $uri, $method);
        $middlewares = $this->config->get('middlewares.' . $serverName, []);
        $middlewares = array_merge($middlewares, $registeredMiddlewares);
        $middlewares = MiddlewareManager::sortMiddlewares($middlewares);
        $relatedFiles = $this->resolveRelatedFiles($uri, $action, $middlewares);

        $data[$unique] = [
            'server' => $serverName,
            'methods' => [$method],
            'uri' => $uri,
            'action' => $action,
            'middleware' => $middlewares,
            'prefix' => $this->extractPrefix($uri),
            'related_files' => $relatedFiles,
            'related_file_paths' => $this->flattenRelatedFilePaths($relatedFiles),
        ];
    }

    private function renderTable(array $routes): string
    {
        $rows = [];
        foreach ($routes as $route) {
            $rows[] = [
                $route['server'],
                implode('|', $route['methods']),
                $route['uri'],
                $route['action'],
                implode(PHP_EOL, $route['middleware']),
            ];
            $rows[] = new TableSeparator();
        }

        if ($rows !== []) {
            array_pop($rows);
        }

        $buffer = new BufferedOutput();
        $table = new Table($buffer);
        $table
            ->setHeaders(['Server', 'Method', 'URI', 'Action', 'Middleware'])
            ->setRows($rows);
        $table->render();

        return $buffer->fetch();
    }

    private function summarizeByPrefix(array $routes): array
    {
        $summary = [];
        foreach ($routes as $route) {
            $prefix = $route['prefix'];
            if (! isset($summary[$prefix])) {
                $summary[$prefix] = [
                    'route_count' => 0,
                    'methods' => [],
                    'sample_paths' => [],
                ];
            }

            ++$summary[$prefix]['route_count'];
            foreach ($route['methods'] as $method) {
                $summary[$prefix]['methods'][$method] = ($summary[$prefix]['methods'][$method] ?? 0) + 1;
            }
            if (count($summary[$prefix]['sample_paths']) < 5 && ! in_array($route['uri'], $summary[$prefix]['sample_paths'], true)) {
                $summary[$prefix]['sample_paths'][] = $route['uri'];
            }
        }

        uasort($summary, static fn (array $a, array $b) => $b['route_count'] <=> $a['route_count']);
        return $summary;
    }

    private function buildPayload(string $server, ?string $path, string $stamp, array $routes, array $summary, array $allRoutes, ?string $sinceCommit, array $changedFiles): array
    {
        return [
            'generated_at' => date(DATE_ATOM),
            'stamp' => $stamp,
            'server' => $server,
            'path_filter' => $path,
            'since_commit' => $sinceCommit,
            'since_short_commit' => $sinceCommit !== null ? $this->git(sprintf('rev-parse --short %s', escapeshellarg($sinceCommit))) : null,
            'commit' => $this->git('rev-parse HEAD'),
            'short_commit' => $this->git('rev-parse --short HEAD'),
            'branch' => $this->git('branch --show-current'),
            'analyzed_route_count' => count($allRoutes),
            'route_count' => count($routes),
            'prefix_count' => count($summary),
            'changed_file_count' => count($changedFiles),
            'changed_files' => array_values($changedFiles),
            'routes' => array_map(function (array $route) use ($changedFiles) {
                $route['method'] = implode('|', $route['methods']);
                $route['changed_related_files'] = array_values(array_intersect($route['related_file_paths'], $changedFiles));
                unset($route['methods']);
                return $route;
            }, $routes),
            'prefix_summary' => $summary,
        ];
    }

    private function renderSummary(array $payload): string
    {
        $lines = [
            'staff-api route map summary',
            sprintf('generated_at: %s', $payload['generated_at']),
            sprintf('server: %s', $payload['server']),
            sprintf('path_filter: %s', $payload['path_filter'] ?? '(none)'),
            sprintf('stamp: %s', $payload['stamp']),
            sprintf('since_commit: %s', $payload['since_commit'] ?? '(none)'),
            sprintf('since_short_commit: %s', $payload['since_short_commit'] ?? '(none)'),
            sprintf('commit: %s', $payload['commit']),
            sprintf('short_commit: %s', $payload['short_commit']),
            sprintf('branch: %s', $payload['branch']),
            sprintf('analyzed_route_count: %d', $payload['analyzed_route_count']),
            sprintf('route_count: %d', $payload['route_count']),
            sprintf('prefix_count: %d', $payload['prefix_count']),
            sprintf('changed_file_count: %d', $payload['changed_file_count']),
            '',
            'top_prefixes:',
        ];

        foreach ($payload['prefix_summary'] as $prefix => $info) {
            ksort($info['methods']);
            $methodSummary = [];
            foreach ($info['methods'] as $method => $count) {
                $methodSummary[] = sprintf('%s=%d', $method, $count);
            }
            $lines[] = sprintf('- %s: %d routes (%s)', $prefix, $info['route_count'], implode(', ', $methodSummary));
            foreach ($info['sample_paths'] as $samplePath) {
                $lines[] = sprintf('    sample: %s', $samplePath);
            }
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function flattenRelatedFilePaths(array $relatedFiles): array
    {
        $paths = [];

        foreach (['route_file', 'controller_file'] as $key) {
            $path = $relatedFiles[$key]['path'] ?? null;
            if (is_string($path) && $path !== '') {
                $paths[] = $path;
            }
        }

        foreach ($relatedFiles['middleware_files'] ?? [] as $item) {
            $path = $item['path'] ?? null;
            if (is_string($path) && $path !== '') {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    private function resolveChangedFiles(?string $sinceCommit): array
    {
        if ($sinceCommit === null) {
            return [];
        }

        $resolvedCommit = trim((string) shell_exec(sprintf(
            'cd %s && git rev-parse --verify %s^{commit} 2>/dev/null',
            escapeshellarg(BASE_PATH),
            escapeshellarg($sinceCommit)
        )));
        if ($resolvedCommit === '') {
            throw new InvalidArgumentException(sprintf('Invalid --since-commit value: %s', $sinceCommit));
        }

        $command = sprintf(
            'cd %s && git diff --name-only %s..HEAD -- 2>/dev/null',
            escapeshellarg(BASE_PATH),
            escapeshellarg($resolvedCommit)
        );
        $output = trim((string) shell_exec($command));
        if ($output === '') {
            return [];
        }

        $files = array_filter(array_map('trim', explode(PHP_EOL, $output)), static fn (string $file) => $file !== '');
        sort($files);
        return array_values(array_unique($files));
    }

    private function filterRoutesByChangedFiles(array $routes, array $changedFiles, bool $filterRequested = false): array
    {
        if (! $filterRequested) {
            return $routes;
        }

        if ($changedFiles === []) {
            return [];
        }

        $changedFileLookup = array_fill_keys($changedFiles, true);
        $filtered = [];
        foreach ($routes as $route) {
            $matchedFiles = array_values(array_filter(
                $route['related_file_paths'],
                static fn (string $path) => isset($changedFileLookup[$path])
            ));
            if ($matchedFiles === []) {
                continue;
            }
            $route['changed_related_files'] = $matchedFiles;
            $filtered[] = $route;
        }

        return $filtered;
    }

    private function extractPrefix(string $uri): string
    {
        $trimmed = trim($uri, '/');
        if ($trimmed === '') {
            return 'root';
        }

        $segments = explode('/', $trimmed);
        return $segments[0] !== '' ? $segments[0] : 'root';
    }

    private function resolveRelatedFiles(string $uri, string $action, array $middlewares): array
    {
        $routeFile = $this->resolveRouteFile($uri);
        $controllerFile = $this->resolveActionFile($action);

        return [
            'route_file' => $this->buildFileCommitMetadata($routeFile),
            'controller_file' => $this->buildFileCommitMetadata($controllerFile),
            'middleware_files' => array_values(array_filter(array_map(
                fn (string $middleware) => $this->buildFileCommitMetadata($this->classToAppFile($middleware)),
                $middlewares
            ))),
        ];
    }

    private function resolveRouteFile(string $uri): string
    {
        return match ($this->extractPrefix($uri)) {
            'admin' => 'config/routers/admin.php',
            'appointment' => 'config/routers/appointment.php',
            'digital-sign' => 'config/routers/digital-sign.php',
            'lab' => 'config/routers/lab.php',
            'patient', 'check-in', 'patient-main' => 'config/routers/patient.php',
            'pre' => 'config/routers/pre.php',
            'public' => 'config/routers/public.php',
            'reception' => 'config/routers/reception.php',
            'surgical-timeout' => 'config/routers/surgical-timeout.php',
            'user' => 'config/routers/user.php',
            default => 'config/routes.php',
        };
    }

    private function resolveActionFile(string $action): ?string
    {
        if ($action === 'Closure' || ! str_contains($action, '::')) {
            return null;
        }

        [$class] = explode('::', $action, 2);
        return $this->classToAppFile($class);
    }

    private function classToAppFile(string $class): ?string
    {
        if (! str_starts_with($class, 'App\\')) {
            return null;
        }

        $relativePath = 'app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        return is_file(BASE_PATH . '/' . $relativePath) ? $relativePath : null;
    }

    private function buildFileCommitMetadata(?string $relativePath): ?array
    {
        if ($relativePath === null) {
            return null;
        }

        $relativePath = ltrim($relativePath, '/');
        if (array_key_exists($relativePath, $this->fileCommitCache)) {
            return $this->fileCommitCache[$relativePath];
        }

        $absolutePath = BASE_PATH . '/' . $relativePath;
        if (! is_file($absolutePath)) {
            return $this->fileCommitCache[$relativePath] = [
                'path' => $relativePath,
                'exists' => false,
                'commit' => null,
                'short_commit' => null,
                'commit_time' => null,
            ];
        }

        [$commit, $commitTime] = $this->gitFileCommitInfo($relativePath);

        return $this->fileCommitCache[$relativePath] = [
            'path' => $relativePath,
            'exists' => true,
            'commit' => $commit,
            'short_commit' => $commit ? substr($commit, 0, 8) : null,
            'commit_time' => $commitTime,
        ];
    }

    private function toAbsolutePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return BASE_PATH . '/' . ltrim($path, '/');
    }

    private function git(string $command): string
    {
        $output = shell_exec('cd ' . escapeshellarg(BASE_PATH) . ' && git ' . $command . ' 2>/dev/null');
        $output = trim((string) $output);
        return $output !== '' ? $output : 'unknown';
    }

    private function gitFileCommitInfo(string $relativePath): array
    {
        $command = sprintf(
            'cd %s && TZ=UTC git log -1 --date=format-local:"%%Y-%%m-%%dT%%H:%%M:%%SZ" --format="%%H %%ad" -- %s 2>/dev/null',
            escapeshellarg(BASE_PATH),
            escapeshellarg($relativePath)
        );
        $output = trim((string) shell_exec($command));
        if ($output === '') {
            return [null, null];
        }

        $parts = preg_split('/\s+/', $output, 2);
        $commit = $parts[0] ?? null;
        $commitTime = $parts[1] ?? null;
        return [$commit !== '' ? $commit : null, $commitTime !== '' ? $commitTime : null];
    }
}
