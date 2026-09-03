# BULA-IA

BULA-IA is a Laravel application for private pharmaceutical knowledge ingestion and administrative document review. The current foundation includes internal ingestion APIs, private PDF attachment, chunk persistence, scoped Bearer Token authentication, admin session login, and a Livewire document inspection surface.

## Repository

```sh
git remote add origin https://github.com/BiGSerial/bulai.git
git branch -M main
git push -u origin main
```

The local repository is configured to publish to:

```sh
https://github.com/BiGSerial/bulai.git
```

## Stack

- PHP 8.4
- Laravel 13
- Livewire 4
- PostgreSQL 17 with pgvector
- PHPUnit 12
- Tailwind CSS 4 through Vite

## Local Setup

Install PHP and JavaScript dependencies:

```sh
composer install
npm install
```

Create the environment file and application key:

```sh
cp .env.example .env
php artisan key:generate
```

Start PostgreSQL:

```sh
docker compose up -d postgres
```

Run migrations:

```sh
php artisan migrate:fresh
```

Build frontend assets:

```sh
npm run build
```

## Development

Run the Laravel development environment:

```sh
composer run dev
```

The root route redirects to the admin document list. Seed an admin user before accessing the admin UI:

```sh
BULAI_ADMIN_NAME="BULA-IA Admin"
BULAI_ADMIN_EMAIL="admin@example.com"
BULAI_ADMIN_PASSWORD="change-me-locally"
php artisan db:seed
```

## Tests

Run the application test suite:

```sh
php artisan test
```

Format changed PHP files before committing:

```sh
vendor/bin/pint --dirty --format agent
```

## Internal Ingestion

Issue an ingestion token:

```sh
php artisan knowledge:issue-token "curadoria-dia"
```

Internal API endpoints:

- `POST /api/internal/knowledge/import`
- `POST /api/internal/knowledge/{document}/file`

Both endpoints require a Bearer Token with the `knowledge:ingest` scope.

## Documentation

Technical implementation notes are maintained in `docs/technical-foundation.md`.
