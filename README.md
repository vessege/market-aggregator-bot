# Market Aggregator Bot

Telegram WebApp bot O'zbekiston, Rossiya va global onlayn marketlardagi mahsulotlarni
bir joyga jamlaydi va eng yaxshi takliflarni ko'rsatadi.

> **Bot buyurtma qabul qilmaydi.** Faqat mahsulotni ko'rsatadi va foydalanuvchi
> "Marketda sotib olish" tugmasi orqali to'g'ridan-to'g'ri marketning saytiga o'tadi.

## Asosiy imkoniyatlar

- **Telegram WebApp** — Uzum stilidagi mahsulot katalogi (rasmi, narxi, reytingi,
  sevimlilarga qo'shish)
- **Jonli narxlar** — parserlar har 15 daqiqada (cron orqali) marketlardan
  ma'lumotni yangilab turadi; foydalanuvchi mahsulot kartasini ochganda esa
  narx 5 daqiqadan eski bo'lsa, real-time saytdan qayta tekshiriladi
- **To'liq matnli qidiruv** — MySQL FULLTEXT / SQLite FTS5 + kirill↔lotin
  transliteratsiya ("телефон" ham, "telefon" ham topadi), prefiks qidiruv va
  relevantlik bo'yicha saralash
- **Jonli qidiruv** — foydalanuvchi qidirganda Uzum, Wildberries va OLX'ga
  parallel so'rov yuboriladi, topilgan mahsulotlar bazaga qo'shilib darhol
  natijada ko'rinadi (bir xil so'rov 10 daqiqa TTL kesh bilan cheklanadi)
- **Valyuta normalizatsiyasi** — RUB/USD narxlar UZSga konvertatsiya qilinadi
  (CBU kurslari, `bin/update-rates.php`), saralash/filtr so'mda ishlaydi
- **Modul parserlar** — har bir market uchun alohida driver:
  - ✅ **Uzum Market** (uzum.uz) — JSON API (detail + jonli qidiruv)
  - ✅ **Wildberries** — search.wb.ru va card.wb.ru JSON API (jonli qidiruv)
  - ✅ **OLX** (olx.uz) — ochiq JSON offers API (faqat query bo'yicha)
  - 🔧 Ozon, Yandex Market, AliExpress — driver shabloni mavjud,
    to'liq implementatsiya kerak (har bir saytning anti-bot himoyasi turlicha)
- **Majburiy obuna** — admin paneldan cheksiz miqdordagi kanallar qo'shiladi;
  bot foydalanuvchi obuna bo'lmaganida WebApp'ni ko'rsatmaydi
- **Admin xabar yuborish** — barcha foydalanuvchilarga matn / rasm + caption /
  video + caption ko'rinishida
- **Admin panel** — parser boshqaruvi, mahsulotlar nazorati, foydalanuvchilar
  ro'yxati, kanallar, broadcast tarixi

## Texnologiyalar

| Qism            | Texnologiya              |
|-----------------|--------------------------|
| Backend         | PHP 8.1+                 |
| Frontend        | Vanilla HTML / CSS / JS  |
| Database        | MySQL 5.7+ / MariaDB 10+ (yoki SQLite — dev uchun) |
| Telegram        | Bot API + WebApp         |
| HTTP client     | cURL                     |

## Loyiha tuzilmasi

```
.
├── admin/                  Admin panel (PHP sahifalar)
│   ├── index.php           Dashboard
│   ├── parsers.php         Parser boshqaruvi
│   ├── products.php        Mahsulotlar (faqat ko'rib chiqish)
│   ├── broadcast.php       Xabar yuborish
│   ├── channels.php        Majburiy obuna kanallari
│   ├── users.php           Foydalanuvchilar
│   ├── login.php
│   └── assets/admin.css
├── api/                    WebApp REST API
│   └── index.php
├── public/                 WebApp frontend (HTTPS bilan ochiladi)
│   ├── index.html
│   └── assets/{css,js}
├── src/
│   ├── Core/               Database, Auth, CSRF, Env, Logger, Bootstrap
│   ├── Telegram/           Bot API, Webhook, WebAppAuth, SubscriptionGate
│   ├── Parsers/            BaseParser, Uzum, Wildberries, ParserManager...
│   ├── WebApp/             ProductRepository
│   └── Admin/              BroadcastService
├── bin/                    CLI skriptlari (setup, run-parser, send-broadcast, setup-webhook)
├── db/                     SQL sxemalari (mysql + sqlite)
├── config/                 .env loader
├── storage/                Loglar, yuklangan fayllar (deploy'da yozish ruxsati kerak)
├── webhook.php             Telegram webhook entry point
└── .htaccess               Apache himoya qoidalari
```

## Tezkor ishga tushirish (lokal)

### 1. Talablar

- PHP 8.1+ (with `pdo`, `pdo_mysql` yoki `pdo_sqlite`, `curl`, `mbstring`, `json`)
- MySQL/MariaDB **yoki** SQLite (dev uchun)
- Composer (autoloader uchun)

### 2. Klonlash va sozlash

```bash
git clone https://github.com/vessege/market-aggregator-bot.git
cd market-aggregator-bot
composer install --no-dev   # PSR-4 autoload uchun (Composer'siz ham ishlaydi)
cp .env.example .env
# .env'ni tahrirlang: BOT_TOKEN, BOT_USERNAME, WEBAPP_URL, WEBHOOK_URL, DB_*, ADMIN_*
```

### 3. Ma'lumotlar bazasini yaratish

**MySQL (production):**
```bash
mysql -u root -p -e "CREATE DATABASE market_bot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p -e "CREATE USER 'market_bot'@'localhost' IDENTIFIED BY 'strong-password';"
mysql -u root -p -e "GRANT ALL ON market_bot.* TO 'market_bot'@'localhost';"
php bin/setup.php   # schema + birinchi admin
```

**SQLite (lokal dev):**
```bash
# .env'da DB_DRIVER=sqlite
mkdir -p storage
php bin/setup.php
```

### 4. Webhook va WebApp URL

Telegram WebApp `https://` bo'lishi shart. Lokalda test qilish uchun
[ngrok](https://ngrok.com/) yoki [cloudflared](https://github.com/cloudflare/cloudflared) ishlatishingiz mumkin:

```bash
ngrok http 8080
# `.env`'dagi WEBAPP_URL va WEBHOOK_URL'ni ngrok bergan URL bilan yangilang
php -S 0.0.0.0:8080 -t .
php bin/setup-webhook.php
```

### 5. Birinchi parserni ishga tushirish

```bash
php bin/run-parser.php --source=uzum --limit=50
php bin/run-parser.php --source=wildberries --query=smartfon --limit=30
```

### 6. Cron (jonli yangilanish)

```cron
*/15 * * * *  cd /var/www/marketbot && php bin/run-parser.php --source=uzum --limit=200
0 * * * *     cd /var/www/marketbot && php bin/run-parser.php --source=wildberries --query=smartfon --limit=50
30 9 * * *    cd /var/www/marketbot && php bin/update-rates.php
```

## Yangilash (mavjud baza)

Sxema o'zgargan bo'lsa (qidiruv ustunlari, FTS, login_attempts):

```bash
php bin/migrate.php   # idempotent — bir necha marta ishga tushirish xavfsiz
```

## Testlar

```bash
php tests/run.php     # SQLite in-memory'da to'liq oqim: sxema → upsert → qidiruv
```

## Admin panelga kirish

`https://example.com/admin/login.php`

Default login: `.env`'dagi `ADMIN_USERNAME` / `ADMIN_PASSWORD` (`admin` / `admin123`).
**Birinchi kirgandan keyin parolni o'zgartiring** — `bin/setup.php`'ni qaytadan
ishlating yoki SQL'da yangilang.

## Telegram sozlamalari

1. [@BotFather](https://t.me/BotFather) → `/newbot` → token oling → `.env`'ga
2. `/setdomain` → WebApp URL'ning domeni (HTTPS) → masalan `example.com`
3. `/setmenubutton` → "Do'kon" + WebApp URL (ixtiyoriy)
4. Majburiy obuna kanallariga botni admin sifatida qo'shing (yoqsa
   `getChatMember` ishlamaydi)

## Deploy variantlari

- **Shared hosting (cPanel)** — fayllarni `public_html`'ga yuklang, `.env`'ni
  sozlang, `php bin/setup.php`'ni Cron Jobs'dan bir martalik ishga tushiring,
  WebApp URL = `https://yourdomain.com/public/`
- **VPS** — Nginx/Apache + PHP-FPM + MySQL. `Dockerfile` keyin qo'shish mumkin.

## Cheklovlar va kelajakdagi rejalar

- **Scraping mo'rt** — marketplaces tuzilishi har 2-3 oyda o'zgaradi, parserlar
  yangilashga muhtoj. Ozon / Yandex Market / AliExpress driverlari hozircha
  stub holatda — ular Akamai/SmartCaptcha/Affiliate API talab qiladi
- **Rate limiting** — markets IP'ni bloklashi mumkin. Production uchun
  proxy rotation va distributed crawling kerak bo'lishi mumkin
- **Image caching** — hozir image URL'lar to'g'ridan-to'g'ri marketdan
  ko'rsatiladi. Future: rasmlarni o'zimizga proxy qilib, CDN bilan beramiz

## Litsenziya

MIT — `LICENSE` faylini ko'ring.
