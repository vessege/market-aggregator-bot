# VPS deploy — Ubuntu (Hetzner / generic)

Botni Hetzner Ubuntu VPS'ga ishga tushirish uchun to'liq qo'llanma. Telegram WebApp **HTTPS talab qiladi**, shuning uchun domen yoki Cloudflare Tunnel kerak.

---

## 0. SSH bilan ulanish (allaqachon ulangan bo'lsangiz, o'tkazib yuboring)
```bash
ssh root@<VPS_IP>
# yoki: ssh ubuntu@<VPS_IP>
```

## 1. Tizimni yangilash + asosiy paketlar
```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y software-properties-common ca-certificates curl git unzip ufw
```

## 2. PHP 8.1 + kerakli kengaytmalar
```bash
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y \
  php8.1 php8.1-cli php8.1-fpm \
  php8.1-sqlite3 php8.1-mysql \
  php8.1-curl php8.1-mbstring php8.1-xml php8.1-zip \
  php8.1-gd php8.1-bcmath
php -v   # tasdiqlang: PHP 8.1.x
```

## 3. Composer
```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version
```

## 4. Nginx
```bash
sudo apt install -y nginx
sudo systemctl enable --now nginx php8.1-fpm
```

## 5. Firewall
```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'   # 80 + 443
sudo ufw --force enable
```

## 6. Loyiha kodi
```bash
sudo mkdir -p /var/www
sudo chown -R $USER:$USER /var/www
cd /var/www
git clone https://github.com/vessege/market-aggregator-bot.git marketbot
cd marketbot
composer install --no-dev --optimize-autoloader
```

## 7. `.env` faylni sozlash
```bash
cp .env.example .env
nano .env
```
Quyidagi qiymatlarni o'rnating:
```ini
APP_ENV=production
APP_DEBUG=false

DB_DRIVER=sqlite
DB_SQLITE_PATH=storage/database.sqlite

ADMIN_USERNAME=admin
ADMIN_PASSWORD=<KUCHLI_PAROL_QO'YING>

TELEGRAM_BOT_TOKEN=8753734757:AAFcy1KYq7tzUOaY9ql26TyRa3fHsSklapI
TELEGRAM_WEBHOOK_SECRET=<TASODIFIY_32_BELGI>

# 9-bo'limdan keyin to'ldiriladi (domen yoki tunnel URL)
WEBAPP_URL=
WEBHOOK_URL=
```

`TELEGRAM_WEBHOOK_SECRET` uchun:
```bash
openssl rand -hex 16
```

## 8. Storage huquqlari + DB schema
```bash
mkdir -p storage
chmod -R 775 storage
php bin/setup.php   # SQLite schema + admin user yaratadi
```
Tekshiruv:
```bash
sqlite3 storage/database.sqlite "SELECT COUNT(*) FROM products;"
```

## 9. **HTTPS — qaysi yo'lni tanlaysiz?**

Telegram WebApp ishlash uchun **public HTTPS URL** kerak. Ikki variant:

### A) Sizda domen bor (eng yaxshi)
1. DNS A-record yarating: `bot.yourdomain.com → <VPS_IP>`
2. Quyidagi nginx + Let's Encrypt qadamlarini bajaring (10–11 bo'limlar)

### B) Domen yo'q → Cloudflare Tunnel (bepul, domen kerakmas)
1. Cloudflare hisob oching: https://dash.cloudflare.com/sign-up
2. VPS'da:
```bash
curl -L https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64.deb -o /tmp/cloudflared.deb
sudo dpkg -i /tmp/cloudflared.deb
cloudflared tunnel login   # brauzerda ochiladi, Cloudflare'ga kirib ruxsat bering
cloudflared tunnel create marketbot
cloudflared tunnel route dns marketbot bot.yourdomain.com   # yoki Cloudflare'dan bepul subdomen
```
Yoki **eng tez** (domensiz, sinov uchun):
```bash
cloudflared tunnel --url http://localhost:80
# bir necha soniyadan keyin chiqadi: https://random-words-1234.trycloudflare.com
```
Bu URL'ni `WEBAPP_URL` va `WEBHOOK_URL` uchun ishlatasiz. Lekin **trycloudflare URL har qayta ishga tushirganda o'zgaradi** — production uchun A) yo'lini tanlang.

---

## 10. Nginx konfiguratsiyasi (A yo'li uchun)

`bot.yourdomain.com` o'rnida o'z domeningizni ishlating.

```bash
sudo tee /etc/nginx/sites-available/marketbot > /dev/null <<'EOF'
server {
    listen 80;
    server_name bot.yourdomain.com;
    root /var/www/marketbot;
    index index.html index.php;

    # /storage/, /db/, /.env, /.git/ ga yo'l yo'q
    location ~ /\. { deny all; }
    location ~ ^/(storage|db|src|vendor|bin|config)/ { deny all; }

    # Bosh sahifa → public/
    location = / { return 301 /public/; }

    # WebApp statik fayllar
    location /public/ {
        try_files $uri $uri/ =404;
    }

    # PHP fayllar (api, admin, public, webhook)
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Webhook
    location = /webhook.php {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/webhook.php;
    }

    client_max_body_size 20m;
}
EOF

sudo ln -sf /etc/nginx/sites-available/marketbot /etc/nginx/sites-enabled/marketbot
sudo nginx -t && sudo systemctl reload nginx
```

## 11. SSL sertifikat (A yo'li, Let's Encrypt)
```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d bot.yourdomain.com --redirect --agree-tos -m you@yourdomain.com -n
```
Sertifikat avtomatik yangilanadi (`/etc/cron.d/certbot`).

## 12. `.env` ga URL'larni qo'yish
A yo'li:
```ini
WEBAPP_URL=https://bot.yourdomain.com/public/
WEBHOOK_URL=https://bot.yourdomain.com/webhook.php
```
B yo'li (Cloudflare Tunnel):
```ini
WEBAPP_URL=https://your-tunnel-host/public/
WEBHOOK_URL=https://your-tunnel-host/webhook.php
```

## 13. Telegram webhook'ni o'rnatish
```bash
cd /var/www/marketbot
php bin/setup-webhook.php
# Tasdiqlash:
curl -s "https://api.telegram.org/bot$(grep ^TELEGRAM_BOT_TOKEN .env | cut -d= -f2)/getWebhookInfo" | python3 -m json.tool
# last_error_message bo'sh bo'lishi kerak
```

## 14. BotFather'da WebApp URL'ni qo'yish
[@BotFather](https://t.me/BotFather)'ga yozing:
```
/mybots → @PriceCompairebot → Bot Settings → Configure Mini App → Edit Mini App URL
```
URL: `https://bot.yourdomain.com/public/` (yoki tunnel URL'ingiz)

## 15. Parser'ni cron'ga qo'yish (jonli mahsulotlar uchun)
```bash
crontab -e
```
Quyidagilarni qo'shing:
```
*/15 * * * * cd /var/www/marketbot && /usr/bin/php bin/run-parser.php --source=uzum --limit=100 >> storage/parser.log 2>&1
0 * * * *    cd /var/www/marketbot && /usr/bin/php bin/run-parser.php --source=wildberries --limit=50 --query=smartfon >> storage/parser.log 2>&1
```

## 16. Birinchi parser ishga tushirish (cron'ni kutmasdan)
```bash
cd /var/www/marketbot
php bin/run-parser.php --source=uzum --limit=30 --depth=2
```

## 17. Tekshirish
- WebApp: brauzerda `https://bot.yourdomain.com/public/` — mahsulotlar grid ko'rinishi kerak
- Admin: `https://bot.yourdomain.com/admin/login.php` — `admin` + .env'dagi parol bilan kiring
- Telegram: `https://t.me/PriceCompairebot` → `/start` → "🛍 Do'konni ochish" tugmasi WebApp'ni ochishi kerak

## 18. Fayl huquqlari (xavfsizlik)
```bash
sudo chown -R www-data:www-data /var/www/marketbot/storage
sudo chmod -R 775 /var/www/marketbot/storage
sudo chmod 600 /var/www/marketbot/.env
```

---

## Tez-tez uchraydigan muammolar

**`502 Bad Gateway`** → PHP-FPM ishlamayapti yoki socket noto'g'ri. Tekshirish: `sudo systemctl status php8.1-fpm` va socket yo'li `/var/run/php/php8.1-fpm.sock` ekanligini tasdiqlang.

**`SQLSTATE: no such table: products`** → `php bin/setup.php` qayta ishga tushiring; `storage/database.sqlite` faylini tekshiring.

**`getWebhookInfo` da `last_error_message: SSL`** → sertifikat noto'g'ri yoki domen DNS hali tarqalmagan. `curl -I https://bot.yourdomain.com/` qaytaradigan kodni tekshiring (200 yoki 301 bo'lishi kerak).

**Cron parser ishlamayapti** → `storage/parser.log` faylini ko'ring; PHP yo'lini tekshiring (`which php`).

**Permission denied storage/** → `sudo chown -R www-data:www-data storage && sudo chmod -R 775 storage`
