# MHM Plugin Updater

GitHub Releases API uzerinden WordPress eklentilerine otomatik guncelleme bildirimi saglayan Composer paketi.

## Kurulum

```bash
composer require maxhandmade/mhm-plugin-updater
```

## Kullanim

Eklentinizin ana dosyasina ekleyin:

```php
\MHM\PluginUpdater\Updater::init([
    'file'  => __FILE__,
    'repo'  => 'MaxHandMade/your-plugin-repo',
    'token' => defined('MHM_GITHUB_TOKEN') ? MHM_GITHUB_TOKEN : null,
]);
```

## Private Repolar

`wp-config.php` dosyasina ekleyin:

```php
define('MHM_GITHUB_TOKEN', 'ghp_your_personal_access_token');
```

## Nasil Calisir

1. WordPress cron (12 saatte bir) guncelleme kontrolu yapar
2. GitHub Releases API uzerinden en son surumu sorgular
3. Yeni surum varsa WordPress admin panelinde bildirim gosterir
4. Kullanici "Guncelle" tiklar, zip GitHub Release'den indirilir ve kurulur

## Gereksinimler

- PHP 8.1+
- WordPress 6.5+

## Lisans

GPL v2 or later
