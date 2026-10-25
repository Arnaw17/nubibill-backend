# NubiBill ERP (Backend REST API & Admin Panel)

The central backend API and administration system for **NubiBill ERP**, powering the companion [NubiBill Flutter client](https://github.com/<YOUR_USERNAME>/nubibill-app). Built with **Laravel 11**, **MySQL 8.4**, **Laravel Sanctum**, and **Filament PHP**.

---

## Architecture Overview

```text
┌────────────────────────────────────────────────────────┐
│               Flutter Client (Front-End)               │
│               Local SQLite Persistence                 │
└───────────────────────────▲────────────────────────────┘
                            │
                            │ HTTPS / REST JSON (Sanctum Tokens)
                            ▼
┌────────────────────────────────────────────────────────┐
│             NubiBill Laravel Backend (This Repo)       │
│                                                        │
│  • REST API endpoints (/api/v1/...)                    │
│  • Multi-tenant business & outlet management           │
│  • Products, Inventory, Invoicing, Godowns             │
│  • Staff Payroll, Attendance & Biometric contracts     │
│  • Filament Web Admin Dashboard (/admin)               │
└───────────────────────────▲────────────────────────────┘
                            │
                            ▼
┌────────────────────────────────────────────────────────┐
│                  MySQL 8.4 Database                    │
└────────────────────────────────────────────────────────┘
```

---

## Tech Stack

- **Framework**: Laravel 11.x (PHP 8.2+)
- **Admin Panel**: Filament 3.x
- **Authentication**: Laravel Sanctum (Personal Access Tokens)
- **Database**: MySQL 8.4 / MariaDB
- **Endpoints**: Structured RESTful endpoints under `/api/v1/...`

---

## Quick Start / Installation

### Prerequisites

1. **PHP**: Version 8.2 or higher with required extensions (`pdo_mysql`, `openssl`, `mbstring`, `curl`, `xml`, `fileinfo`).
2. **Composer**: [Composer package manager](https://getcomposer.org/).
3. **MySQL**: MySQL 8.0+ or compatible database server.

---

### Step-by-Step Setup

1. **Clone the repository**:
   ```bash
   git clone https://github.com/<YOUR_USERNAME>/nubibill-backend.git
   cd nubibill-backend
   ```

2. **Install Composer dependencies**:
   ```bash
   composer install
   ```

3. **Configure Environment Variables**:
   ```bash
   cp .env.example .env
   ```
   Open `.env` and configure your database connection:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=nubibill
   DB_USERNAME=your_db_username
   DB_PASSWORD=your_db_password
   ```

4. **Generate Application Key**:
   ```bash
   php artisan key:generate
   ```

5. **Run Database Migrations & Seeds**:
   ```bash
   php artisan migrate
   ```

6. **Create a Filament Admin User (Optional)**:
   ```bash
   php artisan make:filament-user
   ```

7. **Start the Development Server**:
   ```bash
   php artisan serve
   ```
   The API will be available at: `http://127.0.0.1:8000/api/v1`  
   The Filament Admin Panel will be accessible at: `http://127.0.0.1:8000/admin`

---

## Connecting with the Flutter Frontend

In your Flutter app (`nubibill-app`):
- Run with default local server:
  ```bash
  flutter run -d windows
  ```
- Or pass a custom backend URL:
  ```bash
  flutter run -d windows --dart-define=API_BASE_URL=http://127.0.0.1:8000
  ```

---

## Maintenance & Daily Updates

To push changes to GitHub in the future:
```bash
git status
git add .
git commit -m "feat: description of changes"
git push
```
