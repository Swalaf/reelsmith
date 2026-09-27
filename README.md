# Reelsmith

A self-hosted, white-label AI video platform built with **Laravel 13**. It serves the five
Claude Design screens from the *Reelsmith* project as real, database-backed pages:

| Page | URL | What's real |
|---|---|---|
| **Website** | `/`, `/pricing`, `/templates`, `/docs`, `/contact`, `/legal/*`, `/changelog` … | Plans and templates come from the database, the contact form is stored, and the cookie banner works |
| **Auth** | `/login`, `/register`, `/forgot-password`, `/reset-password/{token}`, `/verify`, `/two-factor`, `/onboarding` | Session login, registration (+ sign-up credits), emailed 6-digit verification codes, password reset, authenticator-app two-factor with recovery codes, and saved onboarding answers |
| **Checkout** | `/checkout` | Stripe, PayPal, Razorpay, Paystack or bank transfer; the plan and credits are applied once the gateway confirms payment (or an admin approves the transfer) |
| **Studio** | `/studio`, `/studio/{screen}` | Idea → AI script → per-scene AI images/clips/uploads → voiceover → captions → FFmpeg render with music, plus live progress, library, templates, brand kit, voice previews, and the account screens: Media Library, Credits & billing, Usage, API keys, Settings (profile, password, 2FA, notifications) and Support tickets |
| **AI Platform** | `/platform`, `/platform/{screen}` | Workflow builder with real, queued runs (test, live webhook and scheduled triggers), run history, studio briefs, cinematic productions with generated shots and an assembled cut, character library, AI agents, repurposing into clips/posts/captions/thumbnails, and API & webhooks |
| **Admin** | `/admin`, `/admin/{screen}` | Live KPIs and charts, users (suspend, delete, credits, impersonate), projects, AI providers (add/edit/test/toggle, encrypted keys), models, templates, plans CRUD, payments (with bank-transfer approval), API keys, white label, pages CMS, settings (incl. cloud storage and "require 2FA for admins") and logs |
| **Installer** | `/install` | Real requirement checks, a database test (MySQL/MariaDB or SQLite) and provider key tests; it writes `.env`, migrates, seeds, creates the admin and then locks itself |
| **REST API** | `/api/v1/*`, `/api/*` | `POST /v1/videos`, `GET /v1/videos/{id}`, `GET /v1/templates`, `POST /v1/scripts`, `GET /v1/credits`, plus `POST /video/generate`, `POST /workflows/run`, `GET /jobs/{id}`, `POST /images/generate` and `POST /agents/{agent}/run`, with `Authorization: Bearer rsk_live_…`, per-key rate limits and request logs |

## Requirements

You need PHP 8.3+ (pdo_mysql or pdo_sqlite, mbstring, curl, openssl, xml, fileinfo) and Composer.
FFmpeg (built with libfreetype for burned-in captions) and cron are optional. There's no Node
build step: React, the icon font and the design runtime are served from `public/`.

## Quick start (local)

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan reelsmith:install --email=admin@example.com --password=Admin12345 --demo
PHP_CLI_SERVER_WORKERS=4 php artisan serve
```

Open http://localhost:8000 and log in as the admin above, or register a new user.
`--demo` adds sample users, projects and payments.

If you'd rather use the browser, skip `reelsmith:install` and open `/install`. Every request
redirects there until installation is finished.

## Production

1. Upload the code, point the web root at `public/`, and run `chmod -R 775 storage bootstrap/cache`.
2. Run `composer install --no-dev --optimize-autoloader`, then open `https://your-domain/install`.
3. Add the cron entry and a queue worker (the installer shows the exact commands):
   ```
   * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
   php artisan queue:work --queue=render,ai,default --tries=3 --timeout=1800
   ```
   With `QUEUE_CONNECTION=database`, renders and workflow runs go to the worker. The default
   `sync` runs them right after the response, which is fine for small installs. Cron drives
   scheduled workflows and the cloud-storage sync.

## How the design is wired in

```
design/src/*.dc.html         original Claude Design files (source of truth, untouched)
design/import.py             splits each design into template + logic and applies small patches
design/extra/*.html          screens the design links to but doesn't draw (Studio account screens), in its style
resources/designs/           generated: <page>.template.html, <page>.logic.js, <page>.props.json
resources/designs/integration/<page>.js   Laravel integration (hand-written)
public/support.js            the Claude Design runtime that renders the pages (React 18)
app/Support/DesignPage.php   assembles a page and injects server data as window.RS
```

Each integration file defines `class Component extends DesignComponent` and overrides only
what needs the backend: loading data and running actions. To pull in a newer version of the
design, replace the files in `design/src/` and run `python3 design/import.py`. The importer fails
loudly if a patch no longer applies.

## How a video gets made

1. **Script**: the connected Text provider writes the script and a timed scene plan. You can use OpenRouter, Groq, Gemini, Cloudflare Workers AI, Hugging Face or any OpenAI-compatible endpoint. With no provider connected, a built-in writer does it.
2. **Visuals**: each scene gets an AI image (Cloudflare Workers AI, Hugging Face, Stability AI, Replicate, Fal.ai or an OpenAI-compatible images API). Scenes set to *AI Video* get a motion clip (Fal.ai, Replicate, Luma or Runway). You can also upload your own image/MP4 or reuse anything from your Media Library. Generate per scene in the Studio, or let the render fill in whatever is missing.
3. **Voice**: each scene's narration is spoken with the chosen voice (ElevenLabs, OpenAI TTS, Edge TTS or Piper).
4. **Render**: FFmpeg builds one segment per scene. Stills get a slow Ken Burns zoom and clips are cropped to fit. Scenes fade between each other, and narration captions are timed to the voiceover in your caption style, with a brand and free-plan watermark. The segments are joined and background music is ducked under the voice. The Studio shows live progress, and the user gets an email when the video is ready.

Every step falls back to the next connected provider when one fails, and every failure is written to **Admin → Logs**. If a scene has no working provider it becomes a colour card or a silent scene rather than failing the render. Only an FFmpeg failure fails the render, and then the credits are refunded.

### Connecting providers (Admin → AI Providers → Edit)

| Provider | What to enter |
|---|---|
| OpenRouter, Groq, OpenAI, custom vLLM/Ollama | API key; base URL is pre-filled (custom: your `/v1` URL) |
| Cloudflare Workers AI | API token, and **Base URL = `https://api.cloudflare.com/client/v4/accounts/<your account id>`** |
| Hugging Face | Access token |
| Google Gemini, Stability AI, Replicate, Fal.ai, Luma, Runway, ElevenLabs | API key |
| Edge TTS (free) | No key. Install it on the server: `pip install edge-tts` |
| Piper (offline) | Install `piper` and set the model to the full path of a `.onnx` voice |

"Test connection" makes a real authenticated request. Keys are stored encrypted with `APP_KEY`.

### Server extras

- **FFmpeg** with libfreetype (the normal distro package, e.g. `apt install ffmpeg`). Captions need `drawtext`. Without it the video still renders, just without captions.
- **Caption fonts**: drop TTF files into `storage/app/fonts/` named after the Studio font (`Archivo Black.ttf`, `Bebas Neue.ttf`, …) or `default.ttf`. Otherwise a system font such as DejaVu Sans Bold is used.
- **Music**: put MP3s at `storage/app/public/music/uplifting-corporate.mp3`, `soft-focus.mp3`, `night-drive.mp3` and `morning-run.mp3`. Tracks without a file use a generated ambient pad.
- **Rendering takes minutes with real providers.** Use a queue worker in production (`QUEUE_CONNECTION=database` and `php artisan queue:work --queue=render,default --timeout=1800`). With `sync`, renders run after the response on PHP-FPM. For local development run `PHP_CLI_SERVER_WORKERS=4 php artisan serve`, because the single-worker dev server blocks while rendering.

## AI Platform

Everything on `/platform` is saved per user and actually runs:

- **Workflows**: nodes are executed in order on the queue. AI Text (optionally as one of your agents), Script, AI Image, AI Video, Voice, Render (makes a real Studio video), Condition, Delay, Transform, Save file, HTTP request, Email, Social (posts to a Zapier/Make webhook) and Output. Use `{input.topic}`, `{copy}`, `{video}` and other variables between steps. "Test workflow" runs it immediately. **Activate** turns on its trigger: a *Webhook* node gets a URL (`POST /hooks/in/{token}`, JSON body = input), and a *Schedule* node runs from cron. Each run has a credit cap (workflow settings).
- **Runs**: every execution with per-step status, timings, credits, outputs and retry.
- **Cinematic**: productions with scenes and shots. Generate shot frames with your image providers, rewrite the screenplay with your text provider, and *Assemble cut* renders the shots into one video.
- **Characters and agents**: reference images for characters, and editable agents (system prompt, model, output format) you can try in place or call from workflows and the API.
- **Repurpose**: turns a rendered video into a 16:9 cut, 9:16 Shorts/Reels/TikTok clips, LinkedIn, X and blog copy, SRT/VTT captions and thumbnail frames.
- **API & webhooks**: outgoing webhooks for `video.completed`, `video.failed`, `run.completed`, `run.failed` and `image.completed`, signed with `X-Reelsmith-Signature: sha256=HMAC(body, secret)`, plus delivery history and API request logs.

## Accounts and security

- **Two-factor authentication**: Studio → Settings → *Turn on two-factor*. Scan the QR code with any authenticator app, confirm a code, and save the 8 one-time recovery codes. Sign-in then asks for a code, and "Trust this device" skips it for 30 days. Admin → Settings → Security → *Require 2FA for admins* sends admins without 2FA to set it up before the admin area opens.
- **API keys**: users create and revoke their own keys under Studio → API, if their plan includes API access (Admin → Plans).
- **Support**: users open tickets under Studio → Support. Administrators see every ticket in the same screen and their replies are emailed to the user. New tickets are emailed to the support address in White Label.

## Payments

Set keys in **Admin → Settings → Payments** (stored encrypted) or in `.env` (`STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `PAYPAL_CLIENT_ID`, `PAYPAL_SECRET`, `PAYPAL_MODE=sandbox|live`, `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET`, `PAYSTACK_SECRET`, `BANK_TRANSFER_DETAILS`). Checkout only offers the gateways that are configured and switched on in Admin → Payments. The currency is set under Settings → Payments.

- **Stripe**: buyers are sent to Stripe Checkout. The plan and credits are applied only after Stripe confirms the payment, either when the buyer returns or via the webhook `POST /webhooks/stripe` (event `checkout.session.completed`, signed with the webhook secret).
- **PayPal**: buyers approve the order on PayPal, and it is captured when they return.
- **Razorpay**: buyers pay on a Razorpay Payment Link (cards, UPI, netbanking). The link is checked with Razorpay when they return, and the webhook `POST /webhooks/razorpay` (event `payment_link.paid`) covers closed tabs.
- **Paystack**: buyers pay on Paystack's hosted page. The transaction is verified when they return, and via `POST /webhooks/paystack` (`charge.success`, signed with your secret key).
- **Bank transfer**: buyers get your bank details and a payment reference (on screen and by email). The order stays *Pending* until an admin clicks **Approve** in Admin → Payments, which applies the plan and credits and emails the buyer.
- **No gateway configured**: on a live site checkout is refused. Only local/debug installs complete orders in test mode.

## Storage

Files are always written to `storage/app/public` first, because FFmpeg needs real files. To serve renders and media from a bucket, fill in **Admin → Settings → Storage**: driver, endpoint (for R2, Wasabi, B2 or MinIO), bucket, access key, secret key, region and an optional public/CDN URL. *Connection* runs a live write/delete test. You can also set `MEDIA_DISK=s3` and the `AWS_*` variables (the installer does this).

With a bucket configured, finished renders, uploads, run outputs and generated images are copied to it and their links point there. The bucket must allow public reads, or use a public/CDN URL. `php artisan reelsmith:storage-sync` runs every 5 minutes to copy anything missed. Add `--prune-days=30` to delete local copies of renders older than 30 days that are safely in the bucket; they are fetched back automatically if needed again (e.g. for repurposing).

## Email

Set SMTP in **Admin → Settings → Email** (host, port, from address, username, password). It overrides `.env`. New users get a 6-digit verification code (the toggle is under Settings → General → *Require email verification*), and until they verify they can't create or render videos. Password resets and "your video is ready" emails use the same mailer.

## Notes

- The Studio and Platform poll for progress while renders and runs are active. Use a queue worker (or PHP-FPM) in production; PHP's built-in single-worker server holds requests while a job runs.
- Provider and gateway calls are real HTTP requests. Test your keys with *Test connection* (providers) and with the gateway's sandbox/test keys before going live.
