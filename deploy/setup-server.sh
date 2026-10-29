#!/usr/bin/env bash
#
# Klargør en ny Ubuntu 24.04-VPS til Lagerstyring. Køres én gang som root:
#
#   sudo bash setup-server.sh lager.itxpartner.dk git@github.com:shadowmandk/lager.git din@email.dk
#
# Installerer PHP 8.4, nginx, Composer, HTTPS-certifikat (Let's Encrypt), firewall og cron.
# Opretter brugeren "lager", som ejer koden og kører PHP. Scriptet kan køres igen uden skade.
#
set -euo pipefail

DOMAIN="${1:?Angiv domæne, fx lager.itxpartner.dk}"
REPO="${2:?Angiv git-adressen til det private repo, fx git@github.com:shadowmandk/lager.git}"
EMAIL="${3:?Angiv en email til Let's Encrypt (bruges til besked om udløbende certifikat)}"

APP_USER=lager
APP_DIR=/var/www/lagerstyring
PHP=8.4

[[ $EUID -eq 0 ]] || { echo "Kør scriptet som root (sudo)."; exit 1; }

echo "==> Pakker"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -yq software-properties-common ca-certificates curl unzip git sqlite3 ufw unattended-upgrades
add-apt-repository -y ppa:ondrej/php
apt-get update -q
apt-get install -yq nginx certbot python3-certbot-nginx \
    php$PHP-fpm php$PHP-cli php$PHP-sqlite3 php$PHP-mbstring php$PHP-xml php$PHP-curl \
    php$PHP-zip php$PHP-intl php$PHP-bcmath php$PHP-opcache

echo "==> Composer"
if ! command -v composer >/dev/null; then
    EXPECTED="$(curl -fsSL https://composer.github.io/installer.sig)"
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    ACTUAL="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
    [[ "$EXPECTED" == "$ACTUAL" ]] || { echo "Composer-installeren har forkert checksum."; exit 1; }
    php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm /tmp/composer-setup.php
fi

echo "==> Bruger $APP_USER"
id -u $APP_USER >/dev/null 2>&1 || adduser --system --group --shell /bin/bash --home /home/$APP_USER $APP_USER
mkdir -p /home/$APP_USER/.ssh "$APP_DIR"
chown -R $APP_USER:$APP_USER /home/$APP_USER "$APP_DIR"
chmod 700 /home/$APP_USER/.ssh

# Deploy-nøgle: serveren må kun læse det private repo.
if [[ ! -f /home/$APP_USER/.ssh/id_ed25519 ]]; then
    sudo -u $APP_USER ssh-keygen -t ed25519 -N "" -C "lager@$DOMAIN" -f /home/$APP_USER/.ssh/id_ed25519 -q
fi
sudo -u $APP_USER sh -c 'ssh-keyscan -t ed25519 github.com >> ~/.ssh/known_hosts 2>/dev/null; sort -u -o ~/.ssh/known_hosts ~/.ssh/known_hosts'

echo "==> PHP"
cat > /etc/php/$PHP/fpm/pool.d/lager.conf <<EOF
[lager]
user = $APP_USER
group = $APP_USER
listen = /run/php/lager.sock
listen.owner = www-data
listen.group = www-data
pm = ondemand
pm.max_children = 10
pm.process_idle_timeout = 30s
php_admin_flag[log_errors] = on
php_admin_value[error_log] = /var/log/php-lager.log
EOF
touch /var/log/php-lager.log && chown $APP_USER:$APP_USER /var/log/php-lager.log
rm -f /etc/php/$PHP/fpm/pool.d/www.conf
sed -i 's/^expose_php = .*/expose_php = Off/' /etc/php/$PHP/fpm/php.ini
sed -i 's/^;\?opcache.enable=.*/opcache.enable=1/' /etc/php/$PHP/fpm/php.ini
systemctl restart php$PHP-fpm

echo "==> nginx"
cat > /etc/nginx/sites-available/lagerstyring <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;
    root $APP_DIR/public;
    index index.php;
    charset utf-8;
    server_tokens off;
    client_max_body_size 10m;

    add_header Strict-Transport-Security "max-age=31536000" always;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    # Byggede CSS/JS har versionsnumre i filnavnet og kan caches længe.
    location /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        add_header Strict-Transport-Security "max-age=31536000" always;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ ^/index\.php(/|\$) {
        fastcgi_pass unix:/run/php/lager.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    # Alle andre .php-filer og skjulte filer (.env, .git) er lukket.
    location ~ \.php\$ { return 404; }
    location ~ /\.(?!well-known).* { deny all; }
}
EOF
ln -sf /etc/nginx/sites-available/lagerstyring /etc/nginx/sites-enabled/lagerstyring
rm -f /etc/nginx/sites-enabled/default
sed -i 's/# server_tokens off;/server_tokens off;/' /etc/nginx/nginx.conf
nginx -t
systemctl reload nginx

echo "==> Firewall"
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable

echo "==> Cron (backup kl. 02 og Microbizz hver time 6-18)"
cat > /etc/cron.d/lagerstyring <<EOF
* * * * * $APP_USER cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1
EOF
chmod 644 /etc/cron.d/lagerstyring

echo "==> HTTPS"
DNS_IP="$(getent ahostsv4 "$DOMAIN" | awk 'NR==1 {print $1}' || true)"
SERVER_IP="$(curl -fsS4 https://ifconfig.me 2>/dev/null || true)"
if [[ -n "$DNS_IP" && "$DNS_IP" == "$SERVER_IP" ]]; then
    certbot --nginx -d "$DOMAIN" -m "$EMAIL" --agree-tos --redirect -n
else
    echo "!! $DOMAIN peger ikke på denne server endnu. Ret DNS, og kør så:"
    echo "   certbot --nginx -d $DOMAIN -m $EMAIL --agree-tos --redirect -n"
fi

cat <<EOF

Serveren er klar. Næste skridt (se DEPLOY.md):

1. Læg denne nøgle ind som "Deploy key" (kun læseadgang) på GitHub-repoet:

$(cat /home/$APP_USER/.ssh/id_ed25519.pub)

2. Hent koden som brugeren $APP_USER:
   sudo -iu $APP_USER git clone $REPO $APP_DIR

3. Følg resten af "Første installation" i DEPLOY.md.
EOF
