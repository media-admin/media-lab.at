# Installation – Media Lab Agency Starter Kit

## Inhaltsverzeichnis

1. [Voraussetzungen](#voraussetzungen)
2. [Neues Kundenprojekt anlegen](#neues-kundenprojekt-anlegen)
3. [WordPress installieren](#wordpress-installieren)
4. [Dependencies & Build](#dependencies--build)
5. [Plugins & Theme aktivieren](#plugins--theme-aktivieren)
6. [HTTPS & Valet-Konfiguration](#https--valet-konfiguration)
7. [SMTP konfigurieren](#smtp-konfigurieren)
8. [Vite Dev-Server](#vite-dev-server)
9. [Troubleshooting](#troubleshooting)

---

## Voraussetzungen

| Software | Version | Prüfen |
|---|---|---|
| PHP | 8.0+ | `php -v` |
| MySQL / MariaDB | 5.7+ / 10.3+ | `mysql --version` |
| Node.js | 18+ | `node -v` |
| npm | 9+ | `npm -v` |
| Composer | 2.0+ | `composer --version` |
| WP-CLI | 2.8+ | `wp --version` |
| Laravel Valet | 4.x | `valet --version` |

**ACF Pro** – Lizenz erforderlich. Download unter [advancedcustomfields.com](https://www.advancedcustomfields.com/)

---

## Neues Kundenprojekt anlegen

```bash
# In den Valet-Ordner wechseln
cd ~/Valet-Umgebung   # oder ~/Sites je nach Setup

# Starter Kit als Basis klonen
git clone https://github.com/media-admin/media-lab-starter-kit.git org.projektname-2026
cd org.projektname-2026

# Git-History trennen (frisches Repo für den Kunden)
rm -rf .git
git init
git add -A
git commit -m "chore: init project from starter kit"

# Valet-Link setzen
valet link
```

### Setup-Script ausführen

```bash
./scripts/setup-project.sh
```

Das Script fragt nach:

| Feld | Beispiel |
|---|---|
| Projekt Name | `FJDF Rebuild 2026` |
| Theme Slug | `fjdf-theme` |
| Plugin Slug | `fjdf-plugin` |
| Text Domain | `fjdf` |

Das Script benennt automatisch um:
- `cms/wp-content/themes/custom-theme/` → `cms/wp-content/themes/{theme-slug}/`
- `cms/wp-content/plugins/media-lab-project-starter/` → `cms/wp-content/plugins/{plugin-slug}/`
- `vite.config.js`, `vite.config.blocks.js`, `scripts/vite-dev.mjs`, `scripts/deploy-*.js`, `package.json`

---

## WordPress installieren

```bash
# Datenbank anlegen
mysql -u root -e "CREATE DATABASE {slug} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

cd cms

# WordPress herunterladen
wp core download --locale=de_DE

# wp-config.php erstellen
wp core config \
  --dbname={slug} \
  --dbuser=root \
  --dbpass=root \
  --dbprefix={slug}_ \
  --locale=de_DE

# WordPress installieren
# ⚠️ WICHTIG: --url muss /cms enthalten!
wp core install \
  --url=https://org.projektname-2026.localdev/cms \
  --title="Projektname" \
  --admin_user=admin \
  --admin_password=SICHERES_PASSWORT \
  --admin_email=markus.tritremmel@media-lab.at

# home-URL auf Root setzen (ohne /cms)
wp option update home 'https://org.projektname-2026.localdev'

cd ..
```

> **Warum zwei verschiedene URLs?**
> `siteurl` (mit `/cms`) zeigt auf das WordPress-Verzeichnis.
> `home` (ohne `/cms`) zeigt auf die öffentliche Domain.
> Dieses Pattern ist notwendig weil WordPress im `cms/`-Unterordner liegt,
> Valet aber vom Root-Verzeichnis aus serviert.

---

## Dependencies & Build

```bash
npm install
composer install
npm run build
```

> Falls `npm install` keine `node_modules` erstellt oder Pakete fehlen:
> ```bash
> rm -rf node_modules package-lock.json
> npm install
> ```

---

## Plugins & Theme aktivieren

```bash
cd cms

# ACF Pro muss physisch in cms/wp-content/plugins/ liegen
wp plugin activate media-lab-agency-core
wp plugin activate media-lab-seo
wp plugin activate advanced-custom-fields-pro
wp plugin activate {plugin-slug}
wp theme activate {theme-slug}

# Permalinks setzen
wp rewrite structure '/%postname%/'
wp rewrite flush

cd ..
```

---

## HTTPS & Valet-Konfiguration

### wp-content Symlink

Valet serviert statische Dateien vom Root-Verzeichnis. Da WordPress im `cms/`-Unterordner liegt, muss ein Symlink gesetzt werden:

```bash
cd ~/Valet-Umgebung/org.projektname-2026
ln -s cms/wp-content wp-content
```

> Ohne diesen Symlink liefert Valet für alle Asset-Requests HTML statt der Dateien
> → JS/CSS laden nicht, MIME-Type-Fehler in der Browser-Konsole.

### HTTPS in wp-config.php

Da Valet HTTPS terminiert und WordPress das nicht automatisch erkennt, müssen folgende Zeilen in `cms/wp-config.php` **vor** dem `/* That's all */`-Kommentar eingetragen werden:

```php
/* HTTPS hinter Valet/Proxy */
$_SERVER['HTTPS'] = 'on';
define( 'FORCE_SSL_ADMIN', true );
```

> Ohne diese Einträge entsteht ein Redirect-Loop beim Aufruf von `/wp-admin`.

---

## SMTP konfigurieren

Credentials sicher in `cms/wp-config.php` eintragen (nie in der Datenbank):

```php
define('MEDIALAB_SMTP_ENABLED',   true);
define('MEDIALAB_SMTP_HOST',      'smtp.example.com');
define('MEDIALAB_SMTP_PORT',      587);
define('MEDIALAB_SMTP_USER',      'user@example.com');
define('MEDIALAB_SMTP_PASS',      'geheimes-passwort');
define('MEDIALAB_SMTP_ENC',       'tls');
define('MEDIALAB_SMTP_FROM',      'noreply@example.com');
define('MEDIALAB_SMTP_FROM_NAME', 'Projektname');
```

---

## Vite Dev-Server

```bash
# Development mit Hot Reload starten
npm run dev

# Production Build
npm run build

# Watch-Modus ohne Dev-Server
npm run watch
```

> `npm run dev` immer aus dem **Projekt-Root** ausführen, nicht aus `cms/`.

---

## Troubleshooting

### `Cannot find package 'vite'`

`node_modules` fehlt oder ist unvollständig:

```bash
rm -rf node_modules package-lock.json
npm install
```

### Assets laden nicht (MIME-Type-Fehler in der Console)

`wp-content`-Symlink fehlt:

```bash
ln -s cms/wp-content wp-content
```

### `/wp-admin` → Redirect-Loop

`HTTPS`-Einträge in `wp-config.php` fehlen. Siehe [HTTPS & Valet-Konfiguration](#https--valet-konfiguration).

### Frontend ohne Formatierung

Entweder:
1. `hot`-Datei ist veraltert: `rm cms/wp-content/themes/{theme-slug}/assets/hot`
2. `npm run build` wurde noch nicht ausgeführt
3. `wp-content`-Symlink fehlt

### `vite.config.blocks.js` Build schlägt fehl mit `referenceId`-Fehler

Vite 8 unterstützt keine SCSS-Dateien als direkte `rollupOptions.input` Entry Points.
Fix: SCSS-Import in `blocks.js` verlagern:

```js
// Erste Zeile in blocks.js
import "../scss/blocks.scss";
```

Und in `vite.config.blocks.js` den `'blocks-scss'`-Entry entfernen.

### `siteurl` und `home` falsch gesetzt

```bash
cd cms
wp option get siteurl   # muss /cms enthalten
wp option get home      # muss ohne /cms sein

wp option update siteurl 'https://domain.localdev/cms'
wp option update home    'https://domain.localdev'
```
