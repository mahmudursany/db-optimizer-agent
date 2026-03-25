# Packagist Publish Guide (mdj/db-optimizer-agent)

## 1) Prepare package repo
- Create a dedicated GitHub repository (example: `mdj/db-optimizer-agent`).
- Copy content from [packages/db-optimizer-agent](packages/db-optimizer-agent).
- Ensure package root has:
  - [packages/db-optimizer-agent/composer.json](packages/db-optimizer-agent/composer.json)
  - [packages/db-optimizer-agent/src/Providers/DbOptimizerServiceProvider.php](packages/db-optimizer-agent/src/Providers/DbOptimizerServiceProvider.php)
  - [packages/db-optimizer-agent/config/db_optimizer.php](packages/db-optimizer-agent/config/db_optimizer.php)
  - [packages/db-optimizer-agent/routes/web.php](packages/db-optimizer-agent/routes/web.php)
  - [packages/db-optimizer-agent/resources/views/index.blade.php](packages/db-optimizer-agent/resources/views/index.blade.php)
  - [packages/db-optimizer-agent/resources/views/show.blade.php](packages/db-optimizer-agent/resources/views/show.blade.php)
  - [packages/db-optimizer-agent/resources/views/scanner.blade.php](packages/db-optimizer-agent/resources/views/scanner.blade.php)
  - [packages/db-optimizer-agent/README.md](packages/db-optimizer-agent/README.md)

## 2) Version tag
- Commit and push `main`.
- Create semantic tag:
  - `git tag v1.0.0`
  - `git push origin v1.0.0`

## 3) Publish on Packagist
- Sign in at Packagist.
- Submit GitHub repo URL.
- Ensure webhook is enabled for auto-update.

## 4) Install in any Laravel 11 project
- `composer require mdj/db-optimizer-agent --dev`
- `php artisan vendor:publish --tag=db-optimizer-config`
- Add `.env` values:
  - `DB_OPTIMIZER_ENABLED=true`
  - `DB_OPTIMIZER_AGENT_TOKEN=<secret-token>`
  - `DB_OPTIMIZER_ROUTE_PREFIX=_db-optimizer`

## 5) Verify
- Open `/_db-optimizer`
- Test agent ping:
  - `curl -H "Authorization: Bearer <secret-token>" http://your-app.test/_db-optimizer/agent/ping`

## 6) Production policy
- Default: `DB_OPTIMIZER_ENABLED=false`
- Use sampling if enabled:
  - `DB_OPTIMIZER_SAMPLE_RATE=0.1`
- Restrict dashboard and agent access with network/auth policy.
