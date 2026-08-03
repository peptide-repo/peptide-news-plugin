# Peptide News Aggregator v2.6.0 — Complete Implementation & Deployment Plan

**Target Architecture:**
- **Shared Hosting Server**: Prepaid WordPress hosting (MySQL Database `wp_peptide_news_articles`, public WordPress frontend, Admin UI).
- **Hostinger KVM8 VPS (Coolify)**: Dedicated VPS running Coolify to execute the standalone worker (`tools/vps-worker/`) for heavy 3-step OpenRouter LLM processing, PubMed XML extraction, and Google News URL redirect unwrapping.

---

## 1. Current Codebase Status (100% Implemented & Verified)
All v2.6.0 features and adversarial security hardenings have been implemented in `c:\Users\terence\Projects\peptidenews`:
- **89/89 PHPUnit tests passing** (`vendor/bin/phpunit`).
- **REST API Worker Routes (`/wp-json/peptide-news/v1/worker/*`)**: Authenticated via `X-Peptide-News-Token` (with timing-safe comparison and automatic token decryption).
- **Standalone Coolify Worker (`tools/vps-worker/`)**: Complete with `Dockerfile`, `docker-compose.yml`, and `worker.php` supporting both CLI arguments and Coolify environment variables (`WP_URL`, `VPS_TOKEN`, `OPENROUTER_KEY`, `OPENROUTER_MODEL`, `WORKER_ACTION`, `BATCH_SIZE`).
- **3-Step AI Pipeline**:
  - **Step 1 (Gatekeeper)**: Assigns scientific rigor score (1–10) and classifies study type. Rejects low-quality/promotional spam (`score < 3` or `is_spam = true`) by setting `'is_active' => 0`.
  - **Step 2 (Extraction)**: Extracts target peptides, mechanism, experimental model, and keywords, saving checkpoint to `ai_metadata`.
  - **Step 3 (Generation)**: Creates a 300–500 word structured Markdown summary (`## Executive Summary`, `## Scientific Context & Mechanism`, etc.).

---

## 2. Instructions for the Agent: Step-by-Step Execution

### Step 1: Package as an Installable WordPress Plugin ZIP
Before deploying to WordPress, create a clean, installable ZIP archive (`peptidenews-v2.6.0.zip`) that can be uploaded directly via **WordPress Admin -> Plugins -> Add New -> Upload Plugin**:
1. From the parent directory of `peptidenews`, generate a clean zip archive containing the `peptidenews/` root folder while excluding development, test, and git files:
   ```bash
   # Linux / macOS / Bash:
   zip -r peptidenews-v2.6.0.zip peptidenews/ \
       -x "peptidenews/.git/*" \
       -x "peptidenews/tests/*" \
       -x "peptidenews/phpunit.xml*" \
       -x "peptidenews/.phpunit*" \
       -x "peptidenews/scratch/*" \
       -x "peptidenews/*.md"
   ```
   *(On Windows PowerShell, use `Compress-Archive -Path .\peptidenews -DestinationPath .\peptidenews-v2.6.0.zip` after excluding `tests` and `.git`).*
2. **Verify ZIP Archive Structure**: Ensure `peptidenews/peptide-news-plugin.php` is located at the top level inside the zip archive so WordPress correctly recognizes it as a valid plugin.

### Step 2: Deploy & Configure WordPress on Shared Hosting
1. Upload and install `peptidenews-v2.6.0.zip` via WordPress Admin or extract it into `/wp-content/plugins/peptidenews/` on the shared WordPress server.
2. In WordPress Admin -> **Settings -> Peptide News**:
   - Under **NCBI API Key**, enter an NCBI API key (optional, increases PubMed rate limit to 10 req/sec).
   - Under **VPS Worker Integration**, enter a strong secret token (e.g., `coolify_secret_token_2026`) and save.
3. Verify the REST API endpoint responds correctly:
   ```bash
   curl -i -H "X-Peptide-News-Token: coolify_secret_token_2026" \
        "https://your-wordpress-site.com/wp-json/peptide-news/v1/worker/pending?limit=5"
   ```

### Step 3: Deploy Worker Application to Coolify (Hostinger KVM8 VPS)
Use the Coolify UI or Coolify MCP to create and configure the standalone worker:
1. **Create Application in Coolify**:
   - Add a new **Application** and point it to the repository (or upload `tools/vps-worker/`).
   - Set **Build Pack** to `Dockerfile` with path `tools/vps-worker/Dockerfile`.
2. **Configure Environment Variables in Coolify**:
   ```env
   WP_URL=https://your-wordpress-site.com
   VPS_TOKEN=coolify_secret_token_2026
   WORKER_ACTION=process-llm
   OPENROUTER_KEY=sk-or-v1-your-openrouter-api-key
   OPENROUTER_MODEL=google/gemini-2.0-flash-001
   BATCH_SIZE=25
   ```
3. **Configure Scheduled Task (Coolify Cron)**:
   - Under **Scheduled Tasks** / Cron Jobs for the application, create a new schedule:
     - **Cron Expression**: `0 * * * *` *(runs hourly)*
     - **Command**: `php /app/worker.php`
   - Click **Deploy** to build and launch the Alpine PHP container.

---

## 3. Automated Verification Checklist for the Agent
1. **Verify Plugin ZIP Installation**: Confirm `peptidenews-v2.6.0.zip` installs and activates cleanly in WordPress Admin without warnings or missing dependencies.
2. **Run Unit Tests Locally**: Execute `vendor/bin/phpunit` and confirm `OK (89 tests, 231 assertions)`.
3. **Test One-Off Execution in Coolify**:
   - Trigger the Coolify scheduled job or run `php /app/worker.php --action="pending"` inside the container.
   - Confirm it connects to WordPress without HTTP 403 or 500 errors.
4. **Check Gatekeeper Database State**:
   - Confirm in WordPress Admin that legitimate scientific articles (`rigor_score >= 3`) receive structured Markdown summaries and tags.
   - Confirm that rejected promotional spam (`rigor_score < 3` or `is_spam`) is automatically updated with `'is_active' => 0` and excluded from frontend widgets.
