# Proxy Yönetim Paneli ve Bot

Bu proje; **proxy listelerinin yönetimi, hatalı proxy takibi, log izleme
ve bot çalıştırma** işlemlerini web tabanlı bir panel üzerinden yapmayı
sağlar.

## Özellikler

-   **Web Paneli**
    -   Aktif Proxy Listesi görüntüleme ve düzenleme (`proxy.txt`)
    -   Hatalı Proxyler listesi (`hatali_proxy.txt`)\
    -   Kesin Hatalı Proxyler listesi (`kesin_hatali_proxy.txt`)
    -   URL listesi yönetimi (`url.txt`)
    -   Log dosyası canlı izleme (`bot.log`)
    -   Oto yenileme (5--30 sn aralık seçimi)
    -   Proxy geri aktarma (seçili ya da tüm uygunları)
-   **Bot**
    -   Python tabanlı `proxy_bot.py` scripti ile URL isteklerini
        proxy'ler üzerinden gönderir
    -   Hatalı proxyleri otomatik olarak hatalı listesine taşır
    -   Kesin hatalılar tekrar aktif listeye alınmaz
    -   SOCKS (1080 port) ve HTTP proxy tiplerini otomatik ayırt eder
    -   Hızlı timeout ayarları sayesinde ölü proxy'lerde bekleme süresi
        kısalır
-   **Dosya Yapısı**
    -   `proxy.txt` → aktif proxy listesi
    -   `hatali_proxy.txt` → tekrar denenebilir hatalı proxyler
    -   `kesin_hatali_proxy.txt` → kalıcı olarak engellenen proxyler
    -   `bad_counts.json` → proxy hata sayaçları
    -   `url.txt` → test edilecek URL listesi
    -   `bot.log` → bot log çıktısı

## Gereksinimler

-   PHP 8.x (panel tarafı için)

-   Python 3.9+

-   `requests` ve SOCKS desteği için:

    ``` bash
    pip install "requests[socks]"
    ```

## Kurulum

1.  Repo'yu klonla:

    ``` bash
    git clone  https://github.com/mehmetakifsari/proxy-url-redirect-systems.git
    cd proxy-url-redirect-systems
    ```

2.  Web panelini sunucunda `public_html` içine yerleştir.

    -   `bot_panel.php` → ana yönetim ekranı\
    -   `run_bot.php` → bot başlat/durdur işlemleri\
    -   `log_view.php` → log görüntüleme

3.  Python botu çalıştır:

    ``` bash
    python3 proxy_bot.py
    ```

4.  Tarayıcıdan panele gir:

        https://alanadresi.com/bot_panel.php

## Kullanım

-   **Proxy ekleme/silme** → Panelde *Aktif Proxy Listesi* alanından
    kaydet.\
-   **Hatalı proxyleri geri alma** → Hatalı Proxyler bölümünde uygun
    olanları seçip geri aktar.\
-   **Log takibi** → Panel üzerinden bot logları gerçek zamanlı takip
    edebilirsin.\
-   **Bot başlat/durdur** → "Başlat" ve "Durdur" butonları ile yönet.

## Notlar

-   Proxy'ler SOCKS (1080 port) veya HTTP olabilir. Kod otomatik ayırt
    eder.\
-   `kesin_hatali_proxy.txt` içindeki adresler bir daha aktif listeye
    geri alınmaz.\
-   Varsayılan timeout: 6 sn connect, 12 sn read.

## Lisans

MIT
