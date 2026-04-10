# hyperf-ai/router-map

Reusable Hyperf `route:map` command package for route inventory, LLM-friendly JSON export, git-backed file metadata, and changed-API detection.

## What it does

This package adds a `route:map` command to a Hyperf application.

It is designed for two use cases:

1. Human inspection
   - print a Hyperf route table
   - print a prefix/domain summary

2. Tooling and AI workflows
   - emit structured JSON
   - write route-map artifacts to disk
   - include route/controller/middleware git metadata
   - compare current routes against a baseline commit and return only related APIs that changed

## Features

- Adds `php bin/hyperf.php route:map`
- Uses Hyperf router internals instead of scraping terminal output
- Supports 3 stdout formats:
  - `json`
  - `summary`
  - `table`
- Always writes 3 artifacts:
  - raw table text
  - JSON payload
  - summary text
- Enriches each route with related file metadata:
  - route file
  - controller file
  - middleware files
- Captures per-file git metadata:
  - `commit`
  - `short_commit`
  - `commit_time`
- Supports `--path` filtering
- Supports `--since-commit` filtering to return only APIs whose related files changed since a baseline commit
- Includes aggregate prefix summaries and changed-file lists in JSON output

## Installation

### From Packagist

```bash
composer require hyperf-ai/router-map
```

### From GitHub VCS repository

If the package is not yet on Packagist, add the GitHub repository first:

```bash
composer config repositories.hyperf-ai-router-map vcs https://github.com/dickwu/router-map.git
composer require hyperf-ai/router-map:dev-main
```

### Hyperf config provider discovery

The package exposes this config provider:

```php
HyperfAi\RouterMap\ConfigProvider::class
```

In most Hyperf setups, Composer package discovery is enough. If your project does not auto-discover it, register the config provider manually.

## Command

```bash
php bin/hyperf.php route:map
```

### Options

```text
--server=http
    Which Hyperf server router to inspect.

--path=
    Optional substring filter for route URI.

--since-commit=
    Optional baseline commit-ish. When provided, only APIs whose related
    route/controller/middleware files changed since that commit are returned.

--output-dir=runtime/route-maps
    Directory where artifacts are written.

--stamp=
    Optional artifact filename stamp. Default is Ymd_His_<short-commit>.

--format=json
    Output format: json, summary, or table.
```

## Examples

### 1. Default JSON output

```bash
php bin/hyperf.php route:map
```

### 2. Human-readable summary

```bash
php bin/hyperf.php route:map --format=summary
```

### 3. Hyperf-style table output

```bash
php bin/hyperf.php route:map --format=table
```

### 4. Filter by route path

```bash
php bin/hyperf.php route:map --path=/admin
php bin/hyperf.php route:map --path=/appointment/download
```

### 5. Return only changed APIs since a commit

```bash
php bin/hyperf.php route:map --since-commit=HEAD~1
php bin/hyperf.php route:map --since-commit=15c611e1afaf6166517e9b3a8a0d83dd9cc0e8d8
php bin/hyperf.php route:map --since-commit=main
```

### 6. Write artifacts to a custom directory

```bash
php bin/hyperf.php route:map --output-dir=/tmp/route-maps
```

### 7. Use a custom artifact stamp

```bash
php bin/hyperf.php route:map --stamp=nightly_snapshot
```

## Output files

Every run writes 3 files:

- `routes_<stamp>.txt`
- `routes_<stamp>.json`
- `routes_<stamp>_summary.txt`

The command also prints the artifact paths to stderr:

```text
route_map_raw_file=/path/to/routes_<stamp>.txt
route_map_json_file=/path/to/routes_<stamp>.json
route_map_summary_file=/path/to/routes_<stamp>_summary.txt
```

This makes it easy for shell scripts, CI jobs, and AI agents to capture generated artifacts.

## JSON payload structure

Top-level JSON fields include:

```json
{
  "generated_at": "2026-04-10T09:18:14-05:00",
  "stamp": "20260410_091809_d43bc156",
  "server": "http",
  "path_filter": null,
  "since_commit": "15c611e1afaf6166517e9b3a8a0d83dd9cc0e8d8",
  "since_short_commit": "15c611e1",
  "commit": "d43bc156408c21f0e489b9c4449fd8dd6e5b2eb0",
  "short_commit": "d43bc156",
  "branch": "master",
  "analyzed_route_count": 690,
  "route_count": 353,
  "prefix_count": 5,
  "changed_file_count": 44,
  "changed_files": [],
  "routes": [],
  "prefix_summary": {}
}
```

### Route node fields

Each route entry contains fields like:

```json
{
  "server": "http",
  "uri": "/admin/appointment/process-permission/update-by-position",
  "action": "App\\Controller\\Admin\\ProcessPermissionController::updateByPosition",
  "middleware": [
    "App\\Middleware\\CorsMiddleware",
    "App\\Middleware\\AuthAdmin"
  ],
  "prefix": "admin",
  "related_files": {
    "route_file": {
      "path": "config/routers/admin.php",
      "exists": true,
      "commit": "2afafb19919aeb78c991aed424078a7829b7f1c1",
      "short_commit": "2afafb19",
      "commit_time": "2026-04-09T19:40:21Z"
    },
    "controller_file": {
      "path": "app/Controller/Admin/ProcessPermissionController.php",
      "exists": true,
      "commit": "ae0229f893856b9c15340f20da6b333d48648565",
      "short_commit": "ae0229f8",
      "commit_time": "2026-03-25T18:12:18Z"
    },
    "middleware_files": [
      {
        "path": "app/Middleware/CorsMiddleware.php",
        "exists": true,
        "commit": "646dcf77cd6fd5d4b0f537d7fd14dd1feff6a1b9",
        "short_commit": "646dcf77",
        "commit_time": "2026-02-09T14:43:08Z"
      }
    ]
  },
  "related_file_paths": [
    "config/routers/admin.php",
    "app/Controller/Admin/ProcessPermissionController.php",
    "app/Middleware/CorsMiddleware.php",
    "app/Middleware/AuthAdmin.php"
  ],
  "changed_related_files": [
    "config/routers/admin.php"
  ],
  "method": "POST"
}
```

## How changed-API detection works

When `--since-commit` is provided, the command does this:

1. resolves the commit-ish to a real commit
2. runs:

```bash
git diff --name-only <since-commit>..HEAD
```

3. builds the list of changed files
4. matches those files against each route's related files:
   - route file
   - controller file
   - middleware files
5. returns only matched APIs

### Important behavior

- If `--since-commit` is omitted, all analyzed routes are returned.
- If `--since-commit` is provided and no related route/controller/middleware files changed, the returned route list is empty.
- Changes to unrelated files such as docs or views will not create API matches unless those files are part of the route's related file set.

## Route file inference

The package resolves route files by top-level prefix using a simple convention, for example:

- `admin` -> `config/routers/admin.php`
- `appointment` -> `config/routers/appointment.php`
- `reception` -> `config/routers/reception.php`
- `user` -> `config/routers/user.php`
- fallback -> `config/routes.php`

This works best in projects with conventional Hyperf route file layout.

## Middleware and controller resolution

The package attempts to convert app classes into local file paths:

- controller classes under `App\...` -> `app/...php`
- middleware classes under `App\...` -> `app/...php`
- vendor middleware/classes are skipped if they are not app-local files

## package.json usage

You can call the Hyperf command through `package.json`.

### Direct command form

```json
{
  "scripts": {
    "route-map": "composer exec -- php bin/hyperf.php route:map",
    "route-map:summary": "composer exec -- php bin/hyperf.php route:map --format=summary",
    "route-map:table": "composer exec -- php bin/hyperf.php route:map --format=table",
    "route-map:changed": "composer exec -- php bin/hyperf.php route:map --since-commit=HEAD~1",
    "route-map:admin": "composer exec -- php bin/hyperf.php route:map --path=/admin"
  }
}
```

### Through a Composer script alias

If your `composer.json` already defines a script like:

```json
{
  "scripts": {
    "route-map": "php ./bin/hyperf.php route:map"
  }
}
```

Then your `package.json` can be:

```json
{
  "scripts": {
    "route-map": "composer route-map --",
    "route-map:summary": "composer route-map -- --format=summary",
    "route-map:changed": "composer route-map -- --since-commit=HEAD~1"
  }
}
```

## CI / automation examples

### Save a full JSON route map in CI

```bash
php bin/hyperf.php route:map --format=json > /tmp/routes.json
```

### Detect changed APIs since main

```bash
php bin/hyperf.php route:map --format=json --since-commit=origin/main > /tmp/changed-routes.json
```

### Capture artifact path lines

```bash
php bin/hyperf.php route:map --format=summary 2> /tmp/route-map.stderr
cat /tmp/route-map.stderr
```

## Requirements

- PHP 8.3+
- Hyperf command/config/http-server components
- A Git repository if you want commit metadata and `--since-commit` behavior to be meaningful

## Caveats

- Route file resolution is convention-based, not AST-perfect.
- Non-`App\...` controllers and middleware may not resolve to local files.
- `related_file_paths` is included in JSON as a helper field for tooling.
- If the current directory is not a Git repo, commit metadata will be incomplete and changed-file filtering may not behave as expected.

## Repository

- GitHub: https://github.com/dickwu/router-map
- Composer package name: `hyperf-ai/router-map`

## License

MIT
