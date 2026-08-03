# Peptide News Aggregator — Hybrid Shared Hosting + Coolify VPS Architecture

This directory contains the **v2.6.0 Standalone VPS Worker**, designed for high-signal peptide news pipelines where WordPress is hosted on a **shared server (e.g., prepaid shared hosting)** and heavy AI/scraping processes are offloaded to a **dedicated Linux VPS running Coolify** (e.g., Hostinger KVM8).

---

## Why Use a Hybrid Architecture?

### 1. Shared Hosting Server (WordPress + Database)
- **Strengths**: Serves public visitors, runs WordPress admin UI, stores `wp_peptide_news_articles`, and handles lightweight web WP-Cron tasks.
- **Limitations**:
  - PHP execution timeouts (60-second CGI limit).
  - Shared IP addresses may be rate-limited or blocked by Google News or NCBI/PubMed.

### 2. Dedicated VPS Worker (Coolify Container / Scheduled Task)
- **Strengths**:
  - Uncapped execution time for OpenRouter/Gemini 3-Step AI Pipeline calls.
  - Dedicated VPS IP address for reliable PubMed E-utilities XML fetching and Google News redirect URL resolution.
  - Zero load on your shared WordPress server's CPU or memory during AI article generation.
  - Effortless management using **Coolify UI** or Coolify MCP.

---

## How It Works

```
[Shared Hosting: WordPress Server]
  ├── Hosts wp_peptide_news_articles & Web Admin Dashboard
  └── REST API (/wp-json/peptide-news/v1/worker/*)
          ▲                      ▲
          │ 1. GET /pending      │ 3. POST /update (ai_summary, rigor_score)
          ▼                      │
[Coolify Instance on VPS: peptide-news-worker]
  ├── Runs via Coolify Scheduled Task / Docker Container
  └── Calls OpenRouter / Gemini API (3-Step Pipeline: Gatekeeper -> Extract -> Markdown Brief)
```

---

## Deploying on Coolify (Step-by-Step)

### Step 1: Set a Secret Token in WordPress
1. Log into your shared WordPress site dashboard.
2. Go to **Settings** -> **Peptide News**.
3. Under **VPS Worker Integration**, enter a secret token (e.g. `vps_secret_token_12345`) and save.

### Step 2: Deploy to Coolify as a Scheduled Task (Recommended)
1. In your Coolify dashboard, click **+ Add Resource** -> **Application** -> select your Git repository (or use **Dockerfile** deployment).
2. Set the **Build Pack** to `Dockerfile` (pointing to `tools/vps-worker/Dockerfile`).
3. Under **Environment Variables**, configure:
   - `WP_URL`: `https://your-shared-wordpress-site.com`
   - `VPS_TOKEN`: `vps_secret_token_12345`
   - `WORKER_ACTION`: `process-llm`
   - `OPENROUTER_KEY`: `sk-or-v1-...`
   - `OPENROUTER_MODEL`: `google/gemini-2.0-flash-001`
   - `BATCH_SIZE`: `25`
4. Under **Scheduled Tasks** (or Coolify Cron Jobs), create a new schedule:
   - **Cron Expression**: `0 * * * *` (runs every hour)
   - **Command**: `php /app/worker.php`
5. Click **Deploy**. Coolify will build the lightweight Alpine PHP container and execute the job automatically on schedule.

---

## REST API Worker Endpoints

All endpoints require either WordPress admin authentication OR a valid `X-Peptide-News-Token: <your-token>` header:

- `GET /wp-json/peptide-news/v1/worker/pending?limit=20`  
  Returns pending articles requiring AI analysis.
- `POST /wp-json/peptide-news/v1/worker/update`  
  Updates an article with completed AI summaries, tags, and scientific rigor scores.
- `POST /wp-json/peptide-news/v1/worker/ingest`  
  Bulk-ingests new articles scraped directly on the VPS into the WordPress database.
