<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Deployment

This app ships as three Docker Compose services (`app`, `queue`, `scheduler`) plus a `postgres` service, defined in `docker-compose.yml` at this directory's root. Copy `.env.example` to `.env` and fill it in before building.

- **`APP_KEY`** — generate once with `php artisan key:generate --show` and paste it into `.env`. Never rotate it after the first deploy: it backs the `encrypted` cast on `connections.credential_encrypted` (stored SimpleFin credentials), so rotating it makes existing stored credentials undecryptable.
- **`LARAVEL_APP_PORT`** — the host-side port the app listens on. Left with no default in `docker-compose.yml` on purpose (many ports are already in use in prod) — you must set it explicitly in `.env`. This is a different variable from the root NestJS app's `APP_PORT`; the two apps can run side by side.
- **`RUN_MIGRATIONS`** — the `app` service sets this to `1` automatically in `docker-compose.yml`, which runs `php artisan migrate --force` plus the `CategorySeeder`/`CategoryRuleSeeder` seeders on container start. Don't set it globally in `.env`, or the `queue`/`scheduler` containers will also try to run migrations on every restart.
- Build and start: `docker compose -f docker-compose.yml up -d --build` from this directory.

### MCP server (`mcp/`)

`mcp/server.js` is a standalone Node MCP server that drives the deployed container via `docker exec ... php artisan ...` — it does not run inside the container itself. To install it:

```bash
cd mcp
npm install
```

The root `.mcp.json` points Claude Code at `laravel/mcp/server.js`. It expects the app container to be named `finance-hub-laravel-app` (the `docker-compose.yml` default); override with the `FINANCE_HUB_CONTAINER` environment variable if you rename it.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
