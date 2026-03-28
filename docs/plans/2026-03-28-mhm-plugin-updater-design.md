# MHM Plugin Updater — Design Document

**Date:** 2026-03-28
**Status:** Approved

## Problem

MHM tarafından geliştirilen WordPress eklentileri GitHub'da barındırılıyor. Bu eklentileri kullanan müşteri siteleri, yeni sürüm çıktığında habersiz kalıyor. WordPress.org'daki gibi otomatik güncelleme bildirimi ve tek tıkla güncelleme mekanizması gerekiyor.

## Solution

**mhm-plugin-updater** — bağımsız bir Composer paketi. Herhangi bir MHM eklentisi `composer require` ile ekler, tek satır `init()` çağrısıyla GitHub Releases API üzerinden güncelleme sistemi aktif olur.

## Architecture

```
┌─────────────────────────────────────────────────┐
│  Müşteri WordPress Sitesi                       │
│                                                 │
│  ┌──────────────┐   ┌──────────────────────┐   │
│  │ mhm-plugin-a │──▶│  mhm-plugin-updater  │   │
│  └──────────────┘   │  (Composer paketi)    │   │
│  ┌──────────────┐   │                       │   │
│  │ mhm-plugin-b │──▶│  • Updater            │   │
│  └──────────────┘   │  • VersionChecker      │   │
│  ┌──────────────┐   │  • UpdateHandler       │   │
│  │ mhm-plugin-c │──▶│  • PluginInfoProvider  │   │
│  └──────────────┘   │  • TokenManager        │   │
│                     └──────────┬───────────┘   │
│                                │               │
└────────────────────────────────┼───────────────┘
                                 │
                    GitHub Releases API
                                 │
                 ┌───────────────┼───────────────┐
                 │ MaxHandMade GitHub Org         │
                 │  ├─ mhm-plugin-a (public)     │
                 │  ├─ mhm-plugin-b (private)    │
                 │  └─ mhm-plugin-c (public)     │
                 └───────────────────────────────┘
```

## Update Flow

1. WordPress cron (12 saatte bir) → `pre_set_site_transient_update_plugins` tetiklenir
2. `mhm-plugin-updater` kayıtlı her eklenti için GitHub Releases API'yi sorgular
3. Yeni versiyon varsa → WordPress admin panelinde güncelleme bildirimi gösterilir
4. Kullanıcı "Güncelle" tıklar → WordPress zip'i GitHub Release'den indirir ve kurar

## Package Structure

```
mhm-plugin-updater/
├── composer.json
├── src/
│   ├── Updater.php              # Ana giriş noktası (statik init)
│   ├── VersionChecker.php       # GitHub API sorgusu, versiyon karşılaştırma
│   ├── UpdateHandler.php        # WP hook'larına bağlanma (transient, download)
│   ├── PluginInfoProvider.php   # "Eklenti detayları" popup bilgisi (changelog)
│   └── TokenManager.php         # Private repo'lar için GitHub token yönetimi
├── README.md
└── LICENSE
```

## Usage (Plugin Side)

```php
// Eklentinin ana dosyasında, composer autoload sonrası:
\MHM\PluginUpdater\Updater::init([
    'file'    => __FILE__,                    // Eklenti ana dosyası
    'repo'    => 'MaxHandMade/mhm-plugin-a',  // GitHub repo
    'token'   => defined('MHM_GITHUB_TOKEN') ? MHM_GITHUB_TOKEN : null,
]);
```

## WordPress Hooks

| Hook | Purpose |
|---|---|
| `pre_set_site_transient_update_plugins` | Yeni versiyon var mı kontrol et |
| `plugins_api` | "Eklenti detayları" popup'ında changelog göster |
| `upgrader_post_install` | Kurulum sonrası klasör adını düzelt |

## GitHub API

```
GET https://api.github.com/repos/{owner}/{repo}/releases/latest
Accept: application/vnd.github.v3+json
Authorization: token {ghp_xxx}  ← sadece private repo için
```

**Response fields used:**
- `tag_name` → versiyon karşılaştırma (`v1.2.3` → `1.2.3`)
- `zipball_url` → indirme URL'si
- `body` → changelog (Markdown)

## Token Management

- Private repo'lar için: `wp-config.php`'de `define('MHM_GITHUB_TOKEN', 'ghp_xxx...');`
- Public repo'larda token gerekmez
- Token yoksa ve repo private ise → admin notice ile uyarı

## Caching & Rate Limits

- GitHub API sonucu WordPress transient ile cache'lenir (varsayılan: 12 saat)
- Her eklenti için ayrı transient: `mhm_updater_{slug}`
- Unauthenticated: 60 req/saat, authenticated: 5000 req/saat

## Folder Name Fix

GitHub zipball klasör adı `MaxHandMade-mhm-plugin-a-abc1234` formatında gelir. WordPress `mhm-plugin-a` bekler. `upgrader_post_install` hook'u ile rename yapılır.

## Error Handling

- GitHub API ulaşılamaz → sessizce geç, mevcut versiyon kalır
- Token geçersiz → admin'de bir kerelik uyarı notice'ı
- Zip indirme başarısız → WordPress kendi hata mesajını gösterir

## Version Comparison

- Tag formatı: `v1.2.3` veya `1.2.3` — `v` prefix otomatik strip edilir
- PHP `version_compare()` kullanılır
- Eklentinin mevcut versiyonu: ana dosyadaki `Version:` header'ından okunur

## Requirements

- PHP 8.1+
- WordPress 6.5+

## Out of Scope (v1)

- Admin ayar sayfası (token wp-config.php'den gelir)
- Otomatik güncelleme (kullanıcı manuel tıklar)
- Lisans kontrolü
- Rollback mekanizması

## Approach

GitHub Releases API kullanılır. Sen GitHub'a release yaptığında, müşteri siteleri 12 saat içinde bunu otomatik algılar ve WordPress admin panelinde güncelleme bildirimi gösterir.
