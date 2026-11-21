# Guide de Déploiement & Exploitation Production (DEPLOIEMENT-PRODUCTION.md)

**Version :** Kanso-ya V2.0 (PHP 8.5+, SQLite WAL / MySQL, Apache / Nginx)

---

## 1. Prérequis Serveur

- **PHP :** `>= 8.5.0` avec extensions `pdo_sqlite` (ou `pdo_mysql`), `mbstring`, `fileinfo`, `openssl`, `json`.
- **Serveur Web :** Apache 2.4+ (`mod_rewrite`, `mod_headers`, `AllowOverride All`) ou Nginx + PHP-FPM.
- **Node.js (build uniquement) :** `>= 20` pour compiler `npm run build:ts` et `npm run build:css` avant mise en ligne (inutile en exécution production).

---

## 2. Configuration `.env` de Production

Copiez `.env.example` vers `.env` à la racine du projet et vérifiez impérativement :

```ini
APP_ENV=production
QA_HOOKS_ENABLED=0

DB_CONNECTION=sqlite
DB_DATABASE=storage/db/kanso-ya.sqlite

# URL furtive personnalisée pour le Shadow Login Superadmin
SUPERADMIN_URL=/sys-admin-votre-secret
```

> [!CAUTION]
> Ne laissez **jamais** `APP_ENV=testing` ou `QA_HOOKS_ENABLED=1` en production : les hooks QA (`/api/v1/auth/switch-persona` et jetons `stub-*`) sont automatiquement désactivés dès que l'une de ces deux conditions n'est pas remplie.

---

## 3. Déploiement Apache (vHost dédié ou Hébergement Mutualisé)

### 3.1. vHost dédié (Recommandé)
Pointez le `DocumentRoot` exclusivement sur `/public/` :

```apache
<VirtualHost *:443>
    ServerName votre-club.fr
    DocumentRoot "/var/www/kanso-ya/public"

    <Directory "/var/www/kanso-ya/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### 3.2. Hébergement Mutualisé (DocumentRoot sur la racine projet)
Le fichier `/.htaccess` racine bloque automatiquement en `403` les dossiers `/Core/`, `/modules/`, `/storage/`, `/docs/`, `/tests/`, `/vendor/` ainsi que `/.env` et redirige silencieusement le trafic vers `/public/`.

### 3.3. Protections actives dans `/public/.htaccess` et `/public/uploads/.htaccess`
- **Blocage `403`** de tout accès direct à un fichier `.php` hors `index.php` et `api.php`.
- **En-têtes HTTP de sécurité :** `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`.
- **Interdiction d'exécution** de tout script dans `/public/uploads/` et refus absolu (`Require all denied`) sur `/storage/private_uploads/`.

---

## 4. Équivalent Nginx (PHP-FPM)

```nginx
server {
    listen 443 ssl http2;
    server_name votre-club.fr;
    root /var/www/kanso-ya/public;
    index index.php;

    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy "geolocation=(), microphone=(), camera=()" always;

    # Blocage dotfiles, dossiers internes et scripts dans uploads/
    location ~ /\.(?!well-known) { deny all; }
    location ~* ^/(Core|modules|storage|docs|tests|vendor)/ { deny all; }
    location ~* ^/uploads/.*\.(php|phtml|phar|cgi|sh)$ { deny all; }

    # Trafic API -> api.php
    location ^~ /api {
        try_files $uri /api.php?$query_string;
    }

    # Tout autre trafic -> index.php (sauf assets statiques existants)
    location / {
        try_files $uri /index.php?$query_string;
    }

    # Seuls index.php et api.php sont exécutables via PHP-FPM
    location ~ ^/(index|api)\.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
    }

    location ~ \.php$ {
        return 403;
    }
}
```

---

## 5. Build, Sauvegardes & Exploitation

### 5.1. Commandes de Build Pré-Déploiement
```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build:ts && npm run build:css
```

### 5.2. Sauvegardes & Rétention SQLite
- Déclenchables en 1 clic depuis `/sys-console` (`POST /api/v1/sys/backup`) : crée un snapshot `storage/backups/kanso-ya-backup-YYYY-MM-DD-HHMMSS.sqlite`.
- **Politique de rétention automatique :** seules les **10 sauvegardes les plus récentes** sont conservées dans `storage/backups/` ; les plus anciennes sont purgées automatiquement.
- **Restauration :** arrêter les écritures, remplacer `storage/db/kanso-ya.sqlite` par le fichier `.sqlite` sauvegardé (supprimer les anciens `-wal` / `-shm` s'ils existent) et vérifier les droits d'écriture du serveur web sur `storage/db/`.
