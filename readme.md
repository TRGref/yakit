# Yakit

GitHub Pages uzerinde calisan statik yakit fiyatlari arayuzu.

Bu repo veritabani ve hosting API'si kullanmaz. GitHub Actions her gun repodaki `yakit.php` dosyasini calistirir, son fiyatlari ceker ve yalnizca `data/latest.json` dosyasini gunceller. Boylece JSON dosyasi buyuyup sayfa acilisini yavaslatmaz.

## Yapi

- `index.html`: GitHub Pages arayuzu
- `assets/app.js`: JSON okuma, filtreleme ve listeleme
- `assets/styles.css`: sayfa stilleri
- `data/latest.json`: en son cekilen fiyat verisi
- `yakit.php`: fiyat kaynaklarindan verileri ceken yerel PHP API
- `scripts/fetch-latest.mjs`: `yakit.php` dosyasini calistirip JSON dosyasini yazar
- `.github/workflows/update-fuel-data.yml`: gunluk otomatik guncelleme

## Yerelde guncelleme

```bash
npm run update-data
```

Varsayilan PHP dosyasi:

```text
yakit.php
```

Farkli PHP dosyasi kullanmak icin:

```bash
FUEL_PHP_FILE=path/to/yakit.php npm run update-data
```

Belirli sehirleri cekmek icin:

```bash
FUEL_CITIES=ankara,izmir npm run update-data
```

## GitHub Pages

Repo GitHub'a push edildikten sonra GitHub Pages ayarlarinda kaynak olarak GitHub Actions secilebilir. Workflow her calismada `data/latest.json` dosyasini commit eder ve statik siteyi Pages'e deploy eder.
