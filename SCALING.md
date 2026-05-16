# Scaling notes — narxbor.uz

Honest guidance on what the current stack can handle and what to change as
traffic grows. Numbers come from real PHP/MySQL benchmarks on similar shared
hosting setups; treat them as orders of magnitude, not promises.

## TL;DR

| Tier | DAU | Pik so'rov/sek | Holat | Kerak |
|---|---|---|---|---|
| 1 | 0 – 1k | 0–5 | hozirgi hosting yetadi | hech narsa |
| 2 | 1k – 10k | 5–40 | yetadi, lekin optimallashtirsa zo'r | OPcache + CDN |
| 3 | 10k – 100k | 40–400 | optimizatsiyalar majburiy | Redis + FULLTEXT + page cache |
| 4 | 100k+ | 400+ | dedicated infra | DB ajratish, queue, replica |

## Current architecture

- PHP 8.1+ on Apache/PHP-FPM (shared hosting, ISPmanager)
- MySQL 8 (single instance)
- File-system based rate limiter (`storage/ratelimit/*.json`)
- Cron: `bin/refresh-hot-keywords.php` every 10 min, `bin/check-alerts.php` every 5 min, `bin/backup.php` daily
- PWA + service worker (cache-first shell, network-first `/api/`)
- Cache-first search: local DB → opportunistic API → "queued" fallback

## Tier 1 — up to 1,000 DAU (you are here)

**Nothing to do.** A shared PHP host with 1 vCPU and 1 GB RAM handles this
comfortably. Each search is ~50–100 ms and we serve maybe 100 queries/hour
at peak.

## Tier 2 — up to 10,000 DAU (~400 pik soatda, ~7 rps)

Recommended free/cheap wins:

1. **Enable PHP OPcache** in `php.ini`:
   ```
   opcache.enable=1
   opcache.memory_consumption=128
   opcache.max_accelerated_files=20000
   opcache.validate_timestamps=1
   opcache.revalidate_freq=60
   ```
   Expected impact: 20-30 % faster page loads with zero code changes.

2. **Put Cloudflare in front (free plan)**:
   - Add `narxbor.uz` to Cloudflare, change nameservers
   - Page Rule: cache CSS/JS/images for 30 days
   - This cuts ~70 % of bandwidth and serves static assets from edge
   - Free DDoS protection comes with it

3. **Run hot-keywords cron more often**: every 5 min instead of 10 if your
   hosting allows. Larger DB cache = more search hits.

4. **Image hot-link CDN**: marketplaces' image CDNs (e.g. images.uzum.uz)
   are already fast — keep using their URLs, don't re-host.

No infra changes needed. Stays on $5-10/month hosting.

## Tier 3 — up to 100,000 DAU (~70 rps)

Now you need to remove three real bottlenecks:

### 3.1 Move rate limiter to Redis / APCu

`storage/ratelimit/*.json` does a `fopen`/`flock`/`fwrite`/`fclose` per
request. At 70 rps that's 70 file syscalls/sec just for rate limiting.
Redis is ~50× faster.

```php
// src/Core/RateLimiter.php — swap file IO for:
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$cur = $redis->incr("rl:$key:$ip");
if ($cur === 1) $redis->expire("rl:$key:$ip", $window);
return $cur <= $limit;
```

If hosting has no Redis: use APCu (built into PHP), `apcu_inc(...)`.

Effort: 2 hours. Improvement: ~40 % less PHP CPU.

### 3.2 FULLTEXT index for product title search

LIKE `'%query%'` does a full-table scan. With 200,000 products that's slow.

```sql
ALTER TABLE products ADD FULLTEXT INDEX ft_title_desc (title, description);
```

Then in `ProductRepository::search()`, when `q` is present, prefer:

```sql
WHERE MATCH(p.title, p.description) AGAINST(:q IN NATURAL LANGUAGE MODE)
```

Effort: 1 hour. Improvement: 10× faster search, 90 % less DB CPU.

### 3.3 Page cache for /api/?action=products

Same query → same result for the next 30 seconds. Cache it.

```php
$cacheKey = 'api:' . md5($_SERVER['REQUEST_URI']);
if ($cached = $redis->get($cacheKey)) { echo $cached; exit; }
ob_start();
// ... handle request ...
$body = ob_get_clean();
$redis->setex($cacheKey, 30, $body);
echo $body;
```

Effort: 2 hours. Improvement: 70 % fewer DB queries (the most common
searches like "iphone", "samsung" hit the cache).

### 3.4 Hosting upgrade

By Tier 3 you want:
- 2-4 vCPU
- 4 GB RAM
- Dedicated MySQL (not shared)
- Redis 1 GB

Cost: $20-40 USD/month (DigitalOcean / Hetzner / Yandex Cloud).
Or upgrade your ISPmanager plan to a VPS tier.

## Tier 4 — 100,000+ DAU

At this size the architecture itself changes:

1. **Read replica** for MySQL — all `SELECT` queries go to the replica,
   `INSERT/UPDATE` go to primary. PHP's PDO supports this natively.
2. **Dedicated search engine** — Meilisearch or Typesense for product
   search. MySQL FULLTEXT stops scaling past ~1M products.
3. **Background queue** — moves the live-search hop from request thread
   to a worker (Beanstalkd, Redis streams, RabbitMQ). User gets cached
   results instantly; live results stream in via WebSocket.
4. **S3-compatible image storage** + Cloudflare/Bunny CDN — don't rely
   on marketplaces' CDNs for hot-link, they may rate-limit.
5. **Multi-region deploy** — if you scale outside Uzbekistan, edge
   workers closer to users (Cloudflare Workers, Vercel Edge).

Cost: $200-500 USD/month. Engineering effort: 1-2 weeks to migrate.

## What we already did right

- Cache-first architecture from day one
- Async-friendly: opportunistic live refresh, AbortController on frontend
- PWA service worker absorbs repeat visits without hitting backend
- Cron-driven product ingestion (predictable, low-load on upstream APIs)
- Anti-bot defenses: per-IP rate limiting, query length cap, prepared statements
- Graceful degradation: live search can be disabled via `LIVE_SEARCH_ENABLED=0`
  without any user-visible breakage

## Monitoring

Once you have Tier 3 traffic, install monitoring:
- **Server**: Netdata (free, real-time) or Datadog (paid)
- **PHP**: Tideways or New Relic APM
- **Front-end**: Sentry (errors), Plausible Analytics (privacy-respecting traffic)
- **Uptime**: UptimeRobot (free)

## When to ask for help

If any of these happen, time to call in a senior engineer or scale up:
- API response time p95 > 1 second consistently
- MySQL CPU pegged > 80 % during normal hours
- "Connection limit exceeded" errors in app.log
- Disk filling up faster than backups prune (check `storage/backups/`)
- Hot-keywords cron taking longer than 5 minutes per run
