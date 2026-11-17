# ⚡ PixelForge — AI Image Generation Studio & Billing Platform

<p align="center">
  <img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="300" alt="Laravel Logo" />
</p>

<p align="center">
  <b>A production-ready SaaS image generation studio & billing engine built with Laravel 12, Inertia.js v2, Vue 3, Tailwind CSS, and PostgreSQL 18 via Laravel Sail.</b>
</p>

---

## 🌟 Detailed Features Shipped

PixelForge is not just an API wrapper; it is an end-to-end AI SaaS platform with real-time estimation, robust billing, webhook deduplication, and financial reconciliation. Here is an in-depth breakdown of everything shipped:

---

### 1. 🎨 AI Image Generation Studio (`/generations`)

- **Interactive Generation Studio**:
  - **Prompt Composer**: Textarea with real-time character counting and prompt optimization.
  - **Resolution & Aspect Ratio Selector**:
    - `1024x1024` (Square — 1:1)
    - `1536x1024` (Landscape — 3:2)
    - `1024x1536` (Portrait — 2:3)
  - **Quality Tiers**: `low`, `medium`, and `high` tiers that scale output tokens and reasoning depth.
  - **Multi-Modal Reference Images**: Attach up to 4 image URLs. Each image is budgeted at 765 tokens and passed to the model for visual reference.

- **Live Upfront Cost & Token Estimator**:
  - Automatically calculates token usage and costs in real time as the user types (debounced).
  - **Token Breakdown**:
    - **Text Tokens**: Character-based estimation (4 chars/token) with ±15% variance bounds.
    - **Image Tokens**: 765 tokens per reference image.
    - **Output Tokens**: Fixed tokens per quality/resolution (e.g. 1,100 tokens for 1024x1024 medium, 6,450 tokens for 1536x1024 high).
    - **Reasoning Tokens**: Dynamic bounds based on quality tier (e.g. high: 1,000–8,000 tokens).
  - Displays **Min**, **Expected**, and **Max** bounds for both the raw Pixelforge provider cost and the final user retail cost (including platform markup).

- **Upfront Solvency & Balance Validation**:
  - Prevents users from submitting generations if their credit balance is lower than the **maximum potential cost** of the generation.
  - Returns clear validation errors displaying the exact maximum cost required versus their current balance.

- **🧪 Provider Test Directives**:
  Clickable shortcut badges inside the prompt editor for testing provider behavior:
  | Directive | Purpose |
  | :--- | :--- |
  | `[succeed]` | Guarantees generation success without random failure |
  | `[fail]` | Accepted by provider then fails during processing (verified billed at `$0.00`) |
  | `[slow]` | Simulates long-running generation (60–90 seconds) to verify polling & UI loading state |
  | `[duplicate]` | Instructs provider to send duplicate webhooks to test idempotency and deduplication |
  | `[blocked]` | Triggers an immediate 400 content policy violation at submission |
  | `[overloaded]` | Triggers a 503 service overload to verify automated retry with `Retry-After` headers |

- **🖼️ Gallery & History View**:
  - Real-time status badges (`queued`, `processing`, `succeeded`, `failed`).
  - High-resolution image preview with one-click download.
  - **Temporary URL Expiry Indicator**: Displays a warning badge showing when direct provider URLs expire (`image_url_expires_at`).
  - **Audit Drawer / Usage Inspector**: View detailed token breakdown (text, image, output, reasoning tokens) and actual rates for any generation.

- **⚡ Reactive Polling & Localhost Fallback**:
  - Active jobs (`queued`, `processing`) poll every 3 seconds for seamless real-time completion without refreshing the page.
  - **Localhost Fallback Engine**: If webhooks cannot reach `localhost` (such as during local development without a public tunnel), the polling endpoint automatically queries the Pixelforge API directly and updates the database, balance, and UI seamlessly.

---

### 2. 💳 9-Decimal Billing & Credits Engine (`PixelforgePricing`)

- **High-Precision BCMath Arithmetic**:
  - Token calculations often produce fractions of a cent (e.g. `$0.000575000` for 115 text tokens).
  - All financial calculations are computed with **9 decimal places** (`decimal(18, 9)`) using PHP's `bcmath` extension, eliminating floating-point rounding errors.
- **Configurable Platform Markup**:
  - Supports configurable platform profit margins (default **25% markup**, set via `PIXELFORGE_MARKUP_PERCENT=25`).
  - Example: A `$0.373875000` provider cost is accurately billed to the user at `$0.467343750`.
- **Zero-Cost Guarantee on Failures**:
  - Failed or rejected generations cost strictly `$0.000000000`. No credits are deducted from the user.
- **Credit Top-up System (`BuyCreditsModal.vue`)**:
  - Users can purchase credits directly through the UI in packages: `$5`, `$10`, `$25`, `$50`, or `$100`.
  - Immutable audit trail in `credit_transactions` table recording transaction type (`top_up`, `generation_charge`), amount, balance before and after, reference IDs, and human-readable descriptions.

---

### 3. 🛡️ Secure Webhook Pipeline (`/webhooks/pixelforge`)

- **HMAC-SHA256 Signature Verification**:
  - Verifies the `Pixelforge-Signature` header (`t=<timestamp>,v1=<signature>`) using the shared secret (`PIXELFORGE_WEBHOOK_SECRET`).
- **Timestamp Tolerance Protection**:
  - Webhooks older than 300 seconds (5 minutes) are rejected with a 400 error to prevent replay attacks.
- **Deduplication & Idempotency Engine**:
  - Tracks all processed webhook IDs in the `webhook_events` table.
  - Duplicate deliveries (tested with `[duplicate]`) return `204 No Content` and are safely ignored without duplicate charges.
- **Out-of-Order Event Protection**:
  - Final statuses (`succeeded`, `failed`) are protected so delayed `processing` or `queued` webhooks can never overwrite a completed generation.
- **Early Webhook Handling**:
  - If a webhook arrives before the initial `POST /v1/generations` response finishes, it utilizes metadata (`user_id`, `local_id`) to initialize and link the generation record immediately.

---

### 4. 📊 Operator Reconciliation Report (`/admin/report`)

- **Daily Spend vs. Provider Reconciliation**:
  - Reconciles internal database spend against the official Pixelforge provider usage report (`GET /v1/usage`).
  - Displays:
    - Total, Succeeded, and Failed generation counts.
    - Platform User Spend.
    - Actual Provider Cost.
    - Gross Platform Profit.
    - Provider Reported Cost.
    - **Reconciliation Status**: Displays a green `Reconciled` badge when database and provider match to 9 decimals, or flags any discrepancy amount.
- **Per-User Metrics**:
  - Breakdown of each user's total generations, success rate, and total credit spend.
- **Continuous Date Range Filter**:
  - View daily metrics across customizable date ranges (defaults to the last 14 days).

---

### 5. 🛠️ CLI Generation Sync (`pixelforge:sync`)

A built-in Artisan command to reconcile or sync generations directly from the terminal:

```bash
# Sync all pending (queued/processing) generations
./vendor/bin/sail artisan pixelforge:sync

# Sync and import all remote generations from Pixelforge
./vendor/bin/sail artisan pixelforge:sync --all
```

---

## 🛠 Prerequisites

Make sure you have installed on your local machine:
- **[Docker Desktop](https://www.docker.com/products/docker-desktop/)** (Make sure Docker is running)
- **Git**
- **PHP 8.3+** and **Composer** *(Optional if running purely through Sail)*

---

## 🏁 Quick Start: Local Deployment (Beginner Friendly)

Follow these simple steps to get the entire project up and running locally in minutes:

### 1. Clone the Repository
```bash
git clone https://github.com/<your-username>/pixel-forge.git
cd pixel-forge
```

### 2. Copy the Environment Configuration
```bash
cp .env.example .env
```

> [!TIP]
> Add your Pixelforge credentials in `.env`:
> ```dotenv
> PIXELFORGE_API_KEY="your_api_key_here"
> PIXELFORGE_WEBHOOK_SECRET="your_webhook_secret_here"
> ```

### 3. Install PHP Dependencies
```bash
composer install
```
*(If you do not have PHP/Composer installed locally, you can use Sail's runtime directly: `docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html laravelsail/php85-composer:latest composer install --ignore-platform-reqs`)*

### 4. Start Docker Containers (Laravel Sail)
Start your PHP 8.5 application and PostgreSQL 18 database in the background:
```bash
./vendor/bin/sail up -d
```

### 5. Generate Application Key
```bash
./vendor/bin/sail artisan key:generate
```

### 6. Run Database Migrations & Seed Default Account
Set up the PostgreSQL tables and seed the demo administrator account:
```bash
./vendor/bin/sail artisan migrate --seed
```

### 7. Install & Build Frontend Assets
```bash
./vendor/bin/sail npm install
./vendor/bin/sail npm run build
```
*(For active frontend development with hot-reloading, run `./vendor/bin/sail npm run dev` instead).*

### 8. Open in Your Browser!
Visit: **[http://localhost](http://localhost)**

---

## 🔑 Demo Account Credentials

Use these seeded credentials to log in:

- **Email**: `test@example.com`
- **Password**: `password`
- **Initial Balance**: `$50.00`
- **Role**: Admin (`is_admin: true`)

---

## 🐘 Connecting to PostgreSQL via Database GUI (DBeaver / TablePlus)

To inspect the database using DBeaver, TablePlus, or pgAdmin:

| Field | Value |
| :--- | :--- |
| **Host** | `localhost` (or `127.0.0.1`) |
| **Port** | **`15432`** |
| **Database** | `laravel` |
| **Username** | `sail` |
| **Password** | `password` |

---

## 🧪 Running Tests

The application includes a comprehensive test suite of 40 tests (154 assertions):

```bash
# Run tests inside Sail
./vendor/bin/sail test

# Or run directly on host
php artisan test
```

Test coverage includes:
- **`PixelforgePricingTest`**: Verifies 9-decimal calculation matching official Pixelforge documentation worked examples (`$0.373875000` provider cost $\rightarrow$ `$0.467343750` user cost).
- **`GenerationStudioTest`**: Covers upfront estimation, insufficient balance rejection, successful submission, polling, and credit purchases.
- **`WebhookTest`**: Covers HMAC-SHA256 signature verification, expired timestamps, successful and failed event handling, charge deductions, duplicate delivery deduplication, and out-of-order deliveries.
- **`AdminReportTest`**: Covers the operator reconciliation dashboard and external provider usage matching.
- **Auth & Profile Tests**: Complete authentication and user profile test suite.

---

## 🧰 Useful Sail Commands Cheat Sheet

| Task | Command |
| :--- | :--- |
| **Start Sail** | `./vendor/bin/sail up -d` |
| **Stop Sail** | `./vendor/bin/sail down` |
| **Restart Sail** | `./vendor/bin/sail restart` |
| **View App Logs** | `./vendor/bin/sail logs -f` |
| **Run Artisan** | `./vendor/bin/sail artisan <command>` |
| **Sync Generations** | `./vendor/bin/sail artisan pixelforge:sync --all` |
| **PostgreSQL CLI** | `./vendor/bin/sail psql` |
| **Run Vite Dev** | `./vendor/bin/sail npm run dev` |
| **Format Code** | `vendor/bin/pint --format agent` |

---

## ☁️ Production Deployment (Laravel Cloud)

PixelForge is designed to deploy seamlessly to [Laravel Cloud](https://cloud.laravel.com):
- **Build Command**: `composer install --no-dev --optimize-autoloader && npm install && npm run build`
- **Deploy Command**: `php artisan migrate --force && php artisan db:seed --force`
- **Database**: Attach a managed PostgreSQL cluster.
- **Webhook URL**: Set `https://<your-app-domain>/webhooks/pixelforge` in your Pixelforge dashboard.

---

## 📄 License

PixelForge is open-source software licensed under the [MIT License](LICENSE).
