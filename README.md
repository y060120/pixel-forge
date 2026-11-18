# ⚡ PixelForge — AI Image Generation Studio & Billing Engine

<p align="center">
  <img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="300" alt="Laravel Logo" />
</p>

<p align="center">
  <b>A SaaS image generation studio and high-precision billing engine built with Laravel 12, Inertia.js v2, Vue 3, Tailwind CSS, and PostgreSQL 18 via Laravel Sail.</b>
</p>

---

## 🌐 Live URL & Deployment

- **Live URL**: `https://pixelforge.promptedit.dev` (or your configured Laravel Cloud deployment domain)
- **Webhook Endpoint**: `https://<your-domain>/webhooks/pixelforge`

---

## 🏛️ Design Decisions & Trade-offs

### 1. Exactly-Once Billing (Pessimistic Locks + Unique Database Constraint)
- **Problem**: When a webhook, browser polling, and CLI sync converge on the same generation, concurrent execution can cause duplicate credit deductions.
- **Decision**: All status transitions and credit charges execute inside database transactions with pessimistic row locking (`lockForUpdate()`).
- **Defense in Depth**: A unique database constraint on `credit_transactions(type, reference_id)` guarantees at the database engine level that no generation can ever produce more than one `generation_charge`, even under high concurrency.
- **Finality Guarantee**: Once a generation reaches `succeeded` or `failed`, its state is strictly immutable. Subsequent or duplicate webhook deliveries return `204 No Content` and cannot re-trigger pricing calculations, charges, or regressions.

### 2. Credit Hold / Reservation System (Overdraft Prevention)
- **Problem**: Verifying balances against upfront estimates only at submission time allows a user with a low balance to submit multiple jobs in parallel across tabs, causing their balance to go negative when jobs complete.
- **Decision**: When a generation is submitted, the maximum potential cost is atomically held against the user's `reserved_balance` under a row lock.
- **Lifecycle**:
  - `Available Balance = Balance - Reserved Balance`.
  - If `Available Balance < Max Potential Cost`, the submission is blocked.
  - Upon generation success: the actual cost is debited from `balance`, and the hold is released.
  - Upon generation failure or rejection: the full hold is released with `$0.00` charged.

### 3. High-Precision 9-Decimal Arithmetic & Truncation Rules
- **Decision**: Token costs often represent minute fractions of a cent (e.g. `$0.000575000` for 115 text tokens). Floating-point arithmetic introduces rounding inaccuracies over time.
- **Rounding Policy**:
  - All internal financial storage uses `decimal(18, 9)`.
  - Calculations use PHP's `bcmath` extension truncated at **9 decimal places**.
  - User interfaces format and display balances rounded to **4 decimal places** (`$0.0000`).

### 4. Database-Backed Polling vs. External Rate Limits
- **Decision**: Browser polling (`/generations/poll`) reads exclusively from the local application database rather than calling the upstream Pixelforge API on every interval.
- **Rationale**: Having multiple browser tabs poll an external provider directly exhausts rate limits (429) and consumes outbound bandwidth. External synchronization is handled asynchronously via webhooks and the scheduled background sync (`pixelforge:sync`).

### 5. Webhook Security & Strict Secret Configuration
- **Decision**: The webhook receiver requires valid HMAC-SHA256 signatures (`Pixelforge-Signature`), verifies timestamp tolerance (300 seconds) against replay attacks, and immediately returns `500 Internal Server Error` if `PIXELFORGE_WEBHOOK_SECRET` is not configured in production rather than silently bypassing verification.

---

## 🌟 Detailed Features

### 1. 🎨 AI Image Generation Studio (`/generations`)

- **Interactive Generation Studio**:
  - **Prompt Composer**: Textarea with character count tracking.
  - **Resolution & Aspect Ratio Selector**:
    - `1024x1024` (Square — 1:1)
    - `1536x1024` (Landscape — 3:2)
    - `1024x1536` (Portrait — 2:3)
  - **Quality Tiers**: `low`, `medium`, and `high` tiers scaling output tokens and reasoning depth.
  - **Reference Images**: Attach up to 4 image URLs. Each image is budgeted at 765 tokens and passed to the provider.

- **Live Upfront Cost & Token Estimator**:
  - Calculates token usage and costs in real time as the user types (debounced).
  - Fetches and caches live pricing rates from Pixelforge provider (`/v1/pricing`).
  - **Token Breakdown**:
    - **Text Tokens**: Character-based estimation (4 chars/token) with ±15% variance bounds.
    - **Image Tokens**: 765 tokens per reference image.
    - **Output Tokens**: Fixed tokens per quality/resolution (e.g. 1,100 tokens for 1024x1024 medium, 6,450 tokens for 1536x1024 high).
    - **Reasoning Tokens**: Dynamic bounds based on quality tier (e.g. high: 1,000–8,000 tokens).
  - Displays **Min**, **Expected**, and **Max** bounds for provider cost and retail user cost (with configurable platform markup).

- **🧪 Provider Test Directives**:
  Clickable shortcut badges inside the prompt editor for testing provider behavior:
  | Directive | Purpose |
  | :--- | :--- |
  | `[succeed]` | Guarantees generation success without random failure |
  | `[fail]` | Accepted by provider then fails during processing (billed at `$0.00`) |
  | `[slow]` | Simulates long-running generation (60–90 seconds) to verify polling & UI loading state |
  | `[duplicate]` | Instructs provider to send duplicate webhooks to test idempotency and deduplication |
  | `[blocked]` | Triggers an immediate 400 content policy violation at submission |
  | `[overloaded]` | Triggers a 503 service overload to verify automated retry with `Retry-After` headers |

- **🖼️ Gallery & History View**:
  - Real-time status badges (`queued`, `processing`, `succeeded`, `failed`).
  - High-resolution image preview with one-click download.
  - Temporary URL expiry badge showing when direct provider URLs expire (`image_url_expires_at`).
  - Usage Inspector: View detailed token breakdown (text, image, output, reasoning tokens) and actual rates for any generation.

---

### 2. 💳 9-Decimal Billing & Credits Engine (`PixelforgePricing`)

- **Configurable Platform Markup**:
  - Supports configurable platform profit margins (default **25% markup**, set via `PIXELFORGE_MARKUP_PERCENT=25`).
  - Example: A `$0.373875000` provider cost is accurately billed to the user at `$0.467343750`.
- **Zero-Cost Guarantee on Failures**:
  - Failed or rejected generations cost strictly `$0.000000000`. Any held funds are released immediately.
- **Credit Top-up System (`BuyCreditsModal.vue`)**:
  - Users can purchase credits directly through the UI in packages: `$5`, `$10`, `$25`, `$50`, or `$100`.
  - Immutable audit trail in `credit_transactions` recording transaction type (`purchase`, `generation_charge`), amount, `balance_after`, `reference_id`, and description.

---

### 3. 🛡️ Secure Webhook Pipeline (`/webhooks/pixelforge`)

- **HMAC-SHA256 Signature Verification**:
  - Verifies the `Pixelforge-Signature` header (`t=<timestamp>,v1=<signature>`) using the shared secret (`PIXELFORGE_WEBHOOK_SECRET`).
- **Timestamp Tolerance Protection**:
  - Webhooks older than 300 seconds are rejected with a 400 error.
- **Deduplication & Idempotency Engine**:
  - Tracks all processed webhook IDs in `webhook_events`.
  - Duplicate deliveries return `204 No Content` and are safely ignored without duplicate charges.
- **Early Webhook Handling**:
  - If a webhook arrives before the initial `POST /v1/generations` response finishes, it utilizes metadata (`user_id`, `local_id`) to initialize and link the generation record immediately.

---

### 4. 📊 Operator Reconciliation Report (`/admin/report`)

- **Role-Based Authorization**:
  - Restricted to administrators via Laravel `view-admin-report` Gate; non-admin users receive a `403 Forbidden` response.
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

---

### 5. 🛠️ CLI Generation Sync (`pixelforge:sync`)

A built-in Artisan command scheduled to run every minute in `routes/console.php` to reconcile pending generations:

```bash
# Sync all pending (queued/processing) generations
php artisan pixelforge:sync

# Sync and import all remote generations from Pixelforge
php artisan pixelforge:sync --all
```

---

## 🛠 Prerequisites

- **Docker Desktop** (for Laravel Sail with PostgreSQL 18)
- **Git**
- **PHP 8.3+** and **Composer** (optional if running purely through Sail)

---

## 🏁 Quick Start: Local Deployment

### 1. Clone & Configure Environment
```bash
git clone https://github.com/your-username/pixel-forge.git
cd pixel-forge
cp .env.example .env
```

Add your Pixelforge credentials to `.env`:
```dotenv
PIXELFORGE_API_KEY="your_api_key_here"
PIXELFORGE_WEBHOOK_SECRET="your_webhook_secret_here"
```

### 2. Install Dependencies & Start Sail
```bash
composer install
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
```

### 3. Run Migrations & Build Frontend
```bash
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm install
./vendor/bin/sail npm run build
```

Open your browser at **[http://localhost](http://localhost)**.

---

## 🔑 Demo Account Credentials

In local and testing environments, the database seeder sets up a demo administrator:
- **Email**: `test@example.com`
- **Password**: `password`
- **Initial Balance**: `$50.00`
- **Role**: Admin (`is_admin: true`)

> [!NOTE]
> In production environments, default credentials are not seeded. Production admins are configured via `ADMIN_EMAIL` and `ADMIN_PASSWORD` environment variables.

---

## 🧪 Running Tests

The application includes a comprehensive test suite of 49 tests (192 assertions):

```bash
# Run tests inside Sail
./vendor/bin/sail test

# Or run directly on host
php artisan test
```

Test coverage includes:
- **`PixelforgePricingTest`**: Verifies 9-decimal calculation matching official Pixelforge documentation worked examples (`$0.373875000` provider cost $\rightarrow$ `$0.467343750` user cost).
- **`GenerationStudioTest`**: Covers upfront estimation, credit holds and overdraft prevention, 503/429 retries with backoff, connection error handling, and credit purchases.
- **`WebhookTest`**: Covers HMAC-SHA256 signature verification, missing secret refusal (500), expired timestamps, charge deductions, duplicate delivery deduplication, out-of-order deliveries, and early-arriving webhooks.
- **`AdminReportTest`**: Covers gate authorization (403 for non-admins) and reconciliation dashboard matching against provider usage.
- **Auth & Profile Tests**: Complete authentication and user profile test suite.

---

## ☁️ Production Deployment (Laravel Cloud)

PixelForge is designed to deploy to [Laravel Cloud](https://cloud.laravel.com):
- **Build Command**: `composer install --no-dev --optimize-autoloader && npm install && npm run build`
- **Deploy Command**: `php artisan migrate --force`
- **Database**: Managed PostgreSQL cluster.
- **Webhook URL**: Set `https://<your-app-domain>/webhooks/pixelforge` in your Pixelforge dashboard.

---

## 📄 License

PixelForge is open-source software licensed under the [MIT License](LICENSE).
