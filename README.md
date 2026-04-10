# hyperf-ai/router-map

Reusable Hyperf `route:map` command package with git-aware changed-API filtering.

## Features

- Exposes a `route:map` Hyperf command
- Emits JSON, summary, or table output
- Writes route-map artifacts to disk
- Enriches each route with related file git metadata
- Supports `--since-commit=<commit-ish>` to return only APIs affected by changed route/controller/middleware files

## Install

```bash
composer require hyperf-ai/router-map
```

If your app does not auto-discover Hyperf config providers, register:

```php
HyperfAi\\RouterMap\\ConfigProvider::class
```

## Usage

```bash
php bin/hyperf.php route:map
php bin/hyperf.php route:map --format=summary
php bin/hyperf.php route:map --since-commit=15c611e1afaf6166517e9b3a8a0d83dd9cc0e8d8
```

## package.json usage

You can call the composer command from `package.json`:

```json
{
  "scripts": {
    "route-map": "composer exec -- php bin/hyperf.php route:map",
    "route-map:summary": "composer exec -- php bin/hyperf.php route:map --format=summary",
    "route-map:changed": "composer exec -- php bin/hyperf.php route:map --since-commit=HEAD~1"
  }
}
```

Or if your project defines a Composer script:

```json
{
  "scripts": {
    "route-map": "composer route-map --",
    "route-map:changed": "composer route-map -- --since-commit=HEAD~1"
  }
}
```

## License

MIT
