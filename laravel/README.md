# Topline backend (Laravel 13)

The API under `/api` and the server for the website (built into `public/` by `npm run build:laravel` in the folder above).

| Where | What |
| --- | --- |
| `routes/api.php` | every endpoint (see `../docs/API-CONTRACT.md`) |
| `app/Http/Controllers/Api/` | thin controllers |
| `app/Services/` | the rules: orders and stock reservation, payments (Paystack or simulation), payouts, billing, activity |
| `app/Models/`, `database/migrations/` | the tables |
| `routes/console.php` | jobs run by the Hostinger Cron Job (`schedule:run`): deploy steps, payment sweep, daily billing |
| `tests/Feature/` | 83 tests, including a check of every response against `../docs/api-examples.json` |

Commands: `php artisan topline:install [--demo]`, `topline:create-admin`, `topline:deploy`, `topline:payments-sweep`, `topline:billing-sweep`.
