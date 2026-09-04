# Panduan Deploy ke Hostinger Shared Hosting (untuk Pemula)

Panduan langkah demi langkah untuk memasang aplikasi ini (Laravel 13 + PHP 8.3+ + MySQL) di **Hostinger shared hosting (hPanel)**. Ditulis untuk pemula: cukup ikuti urutannya, salin-tempel perintahnya, dan sesuaikan bagian yang ditandai dengan tanda <...> (misalnya <DOMAIN>, <FOLDER_PROYEK>) memakai data milik Anda.

> **Catatan UI:** nama menu di hPanel bisa sedikit berbeda tergantung versi panel/plan Anda. Kalau tidak menemukan nama persis, cari menu dengan ikon/nama yang mirip (mis. Databases, PHP Configuration, SSH Access).

---

## Sebelum mulai

- Hostinger memberi akses **hPanel** (panel web) — biasanya lewat https://hpanel.hostinger.com setelah Anda login akun.
- Pastikan Anda sudah punya **domain terpasang** di Hostinger (beli domain baru atau arahkan domain lama ke Hostinger).
- Di hPanel pastikan nama menu yang akan dipakai: **Websites**, **Files (File Manager)**, **Databases**, **PHP**, **Advanced (SSH Access)**, **Security (SSL)**.
- Kebutuhan versi: **PHP 8.3 atau lebih baru** (Hostinger menyediakan pemilihan versi PHP).

## 0. Gambaran hasil akhir

Di dalam akun Hostinger, folder domain kira-kira berbentuk seperti ini (nama folder bisa beda-beda):

    ~/domains/<DOMAIN>/
    ├── public_html/            <- INI document root (isi = isi folder public/ proyek)
    │   ├── index.php          <- sudah diedit path-nya (lihat Langkah 4)
    │   ├── .htaccess
    │   └── build/ ...         <- hasil npm run build
    └── <FOLDER_PROYEK>/       <- SELURUH proyek Laravel (posisi SEJAJAR public_html)
        ├── app/ bootstrap/ config/ database/
        ├── public/            <- kode asli (tidak dipakai langsung, salinan isinya di public_html)
        ├── vendor/ storage/
        ├── .env               <- dibuat di server (JANGAN di-upload)
        └── artisan

Mengapa begitu? Karena hanya isi folder **public/** yang boleh tampil di web; file `.env`, `vendor`, dsb. harus berada DI LUAR document root agar tidak bisa diakses publik.

---

## 1. Persiapan di laptop (wajib dilakukan sebelum upload)

1. **PHP 8.3+ dan Composer** terpasang di laptop.
2. **Node.js/NPM** terpasang (untuk build CSS/JS).
3. Pastikan aplikasi sehat: 

       php artisan test

   Semua test harus PASS (hijau).
4. **Build aset** (Vite) — hasilnya masuk ke folder `public/build`:

       npm install
       npm run build

5. (Opsional tapi disarankan) cek aplikasi jalan di laptop:

       php artisan serve
       # buka http://localhost:8000, login admin@gentot.local / gentot2026

> Penting: `npm run build` HARUS dijalankan di laptop karena shared hosting Hostinger umumnya **tidak punya Node.js** di SSH. Folder `public/build` hasil build ikut di-upload.

## 2. Buat paket rilis (file zip)

Buat zip dari folder proyek **tanpa** folder berat/rahasia. Dari terminal di dalam folder proyek:

    zip -r gentot-release.zip . \
      -x "node_modules/*" "vendor/*" ".git/*" ".env" \
      "storage/framework/cache/*" "storage/logs/*" ".phpunit.result.cache"

Catatan penting:
- Pastikan `.env.example` dan `.htaccess` ikut di dalam zip (di beberapa aplikasi zip Windows/macOS file tersembunyi bisa tidak ikut — aktifkan tampilkan file tersembunyi, atau pakai 7-Zip dengan opsi include hidden).
- Folder `public/build` **harus ikut** (hasil build Langkah 1.4).
- Di Windows tanpa perintah zip: gunakan 7-Zip/WinRAR dan hapus manual folder `node_modules`, `vendor`, `.git` di dalam zip, serta jangan include file `.env`.

## 3. Upload ke server

### Cara A (termudah): hPanel File Manager
1. hPanel -> **Files** -> **File Manager** -> buka folder `~/domains/<DOMAIN>/` (folder induk, BUKAN public_html).
2. Klik **Upload** -> pilih `gentot-release.zip` -> tunggu selesai.
3. Klik kanan zip -> **Extract** (akan muncul folder proyek di situ).
4. Buka folder hasil extract; **rename** jadi nama pendek misal `gentot` (opsional, memudahkan).

### Cara B: SFTP (FileZilla)
1. hPanel -> **Advanced** -> **SSH Access** -> buat user + password SSH (atau aktifkan SSH).
2. Buka FileZilla -> Site Manager: Host = domain atau IP server, port 22, user & password SSH.
3. Upload seluruh isi zip (atau drag folder proyek) ke `~/domains/<DOMAIN>/`.

---

## 4. Susun folder & perbaiki path di index.php

1. hPanel File Manager -> buka `public_html` -> **pilih semua** isinya (index.php sementara, .htaccess, dsb. — kalau ada) -> Hapus.
   (Berhati-hatilah: ini folder domain Anda; jangan hapus folder di luar public_html.)
2. Buka folder proyek `~/domains/<DOMAIN>/<FOLDER_PROYEK>/public/` -> **pilih semua** -> **Cut** -> masuk ke `public_html` -> **Paste**.
   Hasilnya: `public_html/index.php`, `public_html/.htaccess`, `public_html/build/...`.
3. **Edit `public_html/index.php`** (klik kanan -> Edit): ada 3 baris path yang harus ditambah `<FOLDER_PROYEK>/` di tengah (`..` harus menunjuk ke folder proyek, bukan folder induk domain):

    // SEBELUM (asli):
    if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require __DIR__.'/../vendor/autoload.php';
    $app = require_once __DIR__.'/../bootstrap/app.php';

    // SESUDAH (contoh FOLDER_PROYEK = gentot):
    if (file_exists($maintenance = __DIR__.'/../gentot/storage/framework/maintenance.php')) {
    require __DIR__.'/../gentot/vendor/autoload.php';
    $app = require_once __DIR__.'/../gentot/bootstrap/app.php';

4. Pastikan `public_html/.htaccess` ada (file Laravel default dari folder public/).
5. **Izin folder**: lewat File Manager klik kanan folder `storage` dan `bootstrap/cache` di dalam folder proyek -> **Permissions** -> set **775** (atau 755; jangan 777).

## 5. Buat database MySQL di hPanel

1. hPanel -> **Databases** -> **MySQL Databases**.
2. **Create database**: isi nama (mis. `gentot_db`), simpan. (Hostinger sering menambahkan awalan username, mis. `u123456789_gentot_db` — pakai nama lengkapnya.)
3. **Create user**: buat user (mis. `gentot_user`) + password kuat, simpan.
4. **Add user to database**: pilih database + user -> beri **ALL PRIVILEGES** -> tambahkan.
5. Catat: nama database lengkap, username lengkap, password, dan **DB_HOST** (umumnya `127.0.0.1` di shared hosting).

## 6. Atur PHP & aktifkan ekstensi

1. hPanel -> **PHP** -> pilih website/domain Anda.
2. Set **PHP version = 8.3** (atau 8.4 kalau tersedia; minimal 8.3).
3. Di bagian ekstensi/extension, **aktifkan yang dibutuhkan aplikasi ini** (wajib SEMUA — ada yang khusus untuk **baca** dan ada yang khusus untuk **tulis** Excel):

    pdo_mysql   (koneksi MySQL)
    mbstring    (string/fungsi tanggal Indonesia)
    zip         (membuka kontainer .xlsx - ZipArchive; dipakai baca & tulis)
    gd          (gambar barcode PNG - picqer)
    xml, dom, simplexml, xmlreader, xmlwriter, libxml  (PhpSpreadsheet / Excel)
    fileinfo    (validasi upload file, mis. import Excel: mimes:xlsx)
    iconv, zlib, ctype, filter, openssl, session, tokenizer, hash  (Laravel/dasar)
    curl        (HTTP, jika dipakai)
    intl        (opsional - format tanggal/lokale yang lebih baik)

   > **Wajib dipahami (ini penyebab "upload 500 tapi download/export jalan"):**
   > PhpSpreadsheet **membaca** file .xlsx memakai `simplexml`, `dom`, `xml`, `xmlreader`
   > (parsing XML OOXML); sedangkan **menulis** file .xlsx (download template & export
   > Format A/B) hanya butuh `xmlwriter` + `zip`. Jadi kalau ekstensi XML-reader di atas
   > tidak aktif: **download & export tetap jalan, tapi upload/import Excel gagal**
   > (halaman putih/500). Aktifkan semuanya, simpan, lalu `php artisan optimize:clear`.

4. Simpan. Hostinger akan meng-reload PHP otomatis.

## 7. SSH & pasang dependensi (composer install)

1. Aktifkan SSH: hPanel -> **Advanced** -> **SSH Access** -> buat/enable user SSH (catat user, host, password).
2. Connect dari terminal laptop Anda:

       ssh <USER>@<DOMAIN_ATAU_IP>

   (Mac/Linux langsung di Terminal; Windows pakai PowerShell atau PuTTY.)
3. Masuk ke folder proyek:

       cd ~/domains/<DOMAIN>/<FOLDER_PROYEK>
       php -v

   Pastikan `php -v` menampilkan **8.3.x+**. Kalau CLI menampilkan versi berbeda dari versi web, lihat Troubleshooting (baris PHP CLI vs web).
4. Pasang dependensi produksi:

       composer install --no-dev --optimize-autoloader

   Kalau `composer` tidak dikenali, gunakan composer.phar:

       php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
       php composer-setup.php
       php -r "unlink('composer-setup.php');"
       php composer.phar install --no-dev --optimize-autoloader

## 8. Buat file .env produksi

1. Buka folder proyek di File Manager -> klik kanan **.env.example** -> **Copy** -> paste di folder yang sama -> rename jadi **.env** (hapus akhiran .example).
2. Klik kanan `.env` -> Edit, lalu isi seperti ini (ganti sesuai data Anda):

    APP_NAME="Aplikasi GenTOt"
    APP_ENV=production
    APP_KEY=
    APP_DEBUG=false
    APP_URL=https://<DOMAIN>

    APP_LOCALE=id

    LOG_CHANNEL=stack
    LOG_LEVEL=warning

    DB_CONNECTION=mysql
    DB_HOST=127.0.0.1
    DB_PORT=3306
    DB_DATABASE=<NAMA_DATABASE_LENGKAP>
    DB_USERNAME=<USER_DATABASE_LENGKAP>
    DB_PASSWORD=<PASSWORD_DATABASE>

    SESSION_DRIVER=database
    SESSION_LIFETIME=120
    CACHE_STORE=database
    QUEUE_CONNECTION=database

3. Baris **APP_KEY** diisi dengan salah satu cara:
   - Cara mudah: ambil nilai `APP_KEY` dari file `.env` di laptop Anda (aman — kunci ini hanya untuk enkripsi cookie/session), atau
   - Cara benar: kosongkan lalu jalankan di SSH: `php artisan key:generate`.
4. Simpan. Pastikan file `.env` TIDAK pernah ikut terupload ke area publik (letaknya di folder proyek, bukan di public_html).

## 9. Migrasi database & seed akun admin

Di SSH, dari folder proyek:

    cd ~/domains/<DOMAIN>/<FOLDER_PROYEK>
    php artisan migrate --seed --force

Ini membuat tabel + data awal (pengaturan toko, template export, dsb.) dan akun admin:

    Email: admin@gentot.local
    Password: gentot2026

> **SEGERA ganti password** setelah bisa login: menu Pengaturan -> Keamanan - Ubah Password Admin. Password demo ini dipakai semua orang, jangan biarkan aktif di server publik.

## 10. Cache produksi

    php artisan storage:link          # opsional (aplikasi ini tidak wajib, karena file export tersimpan di storage private)

> **Gunakan SATU binari PHP untuk semua perintah artisan.** Di Hostinger CLI default (`php`) sering berbeda versi dengan web PHP-nya (mis. CLI = 8.2, web = 8.4). Campur dua binari bisa membuat file cache menjadi tidak sinkron. Pakai binari yang sesuai versi web (biasanya `/opt/alt/phpXX/usr/bin/php`) dan jalankan berurutan dalam satu rangkaian:

    /opt/alt/php84/usr/bin/php artisan optimize:clear
    /opt/alt/php84/usr/bin/php artisan config:cache
    /opt/alt/php84/usr/bin/php artisan route:cache
    /opt/alt/php84/usr/bin/php artisan view:cache

> Setiap kali Anda mengubah isi `.env` atau kode, jalankan ulang cache: `optimize:clear` lalu `config:cache` + `route:cache` + `view:cache` (jangan menjalankan beberapa sub-perintah sekaligus dalam satu baris `artisan config:cache route:cache` — artisan hanya menerima SATU perintah per pemanggilan).
>
> **Penting utk route BARU (mis. menambah fitur `/publik`)**: kalau `route:cache` tidak dijalankan ulang setelah upload kode baru, semua route baru akan **404 di hosting**. `optimize:clear` wajib dijalankan dulu (menghapus file `bootstrap/cache/routes-*.php`), baru cache lagi.
>
> **Saat ragu / baru-baru error cache**: cukup jalankan `route:clear` (membuat Laravel membaca `routes/web.php` langsung tiap request) lalu `optimize`/cache satu-rangkaian.

## 11. SSL & HTTPS (wajib kalau pakai https)

1. hPanel -> **Security** -> **SSL** -> aktifkan sertifikat gratis Hostinger (Let's Encrypt) untuk domain Anda, tunggu sampai Status = aktif.
2. Pastikan `APP_URL` di `.env` memakai `https://` (sudah di Langkah 8).
3. (Opsional) paksa http -> https dengan menambahkan di `public_html/.htaccess`, di atas baris `RewriteEngine` yang ada:

    RewriteCond %{HTTPS} off
    RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [L,R=301]

## 12. Backup berkala

1. Backup database via cron di hPanel (**Advanced** -> **Cron jobs**) — contoh setiap hari 03:00:

    mysqldump -u <USER_DB> -p'<PASS_DB>' <NAMA_DB> | gzip > ~/backup/gentot-$(date +%F).sql.gz

   (ganti dengan kredensial DB Anda; hapus tanda kutip jika password tak punya karakter khusus).
2. Cadangkan juga folder proyek (File Manager -> folder -> Archive/Download) secara berkala — cukup file `.env`, `storage/app` (file export), dan `database` bila ada perubahan.

## 13. Troubleshooting (kalau ada masalah)

| Gejala | Kemungkinan penyebab | Solusi |
|---|---|---|
| Layar putih/500 tanpa pesan | APP_DEBUG=false menyembunyikan error | Sementara set `APP_DEBUG=true`, muat ulang, baca pesan; lalu kembali ke false. Atau baca file `storage/logs/laravel.log`. |
| 500 terus walau APP_DEBUG=true | Izin folder storage | `chmod -R 775 storage bootstrap/cache`; pastikan folder dapat ditulis user PHP. |
| 'No application encryption key' | APP_KEY kosong | Jalankan `php artisan key:generate`. |
| 'Class ... not found' / class vendor tidak ditemukan | composer belum jalan | `composer install --no-dev --optimize-autoloader`. |
| halaman muat tetapi tampilan berantakan | aset belum dibuild | Jalankan `npm run build` di laptop, upload ulang folder `public/build`. |
| Login selalu gagal | salah email/password atau seed belum jalan | `php artisan migrate --seed --force`; pastikan akun admin@gentot.local ada di tabel users. |
| Error soal ekstensi (zip/gd/fileinfo) | ekstensi PHP nonaktif | Aktifkan di hPanel -> PHP (Langkah 6), lalu `php artisan optimize:clear` + reload. |
| **Upload Excel pembelian → halaman putih/500, tapi Download Template & Export A/B jalan** | ekstensi **XML-reader** belum aktif di web PHP (CLI SSH bisa beda dgn FPM) — writer cukup `xmlwriter`+`zip`, reader butuh `simplexml`/`dom`/`xml`/`xmlreader` | hPanel -> PHP Configuration -> aktifkan `xml, dom, simplexml, xmlreader` (plus `xmlwriter, libxml, fileinfo, zip`), simpan. Cek juga `storage/logs/laravel.log` utk konfirmasi (cari "Call to undefined function simplexml_load_string" / 'Class "XMLReader" not found'). Diagnosa cepat: menu Pengaturan -> kartu "Status Lingkungan Excel". |
| Upload file Excel ditolak tanpa masuk proses | batas upload kecil | Naikkan `upload_max_filesize` & `post_max_size` (mis. 32M) di hPanel -> PHP Configuration; aplikasi menolak file > 10 MB. |
| **Login → 500 "Rate limiter [login] is not defined"** (baru saja deploy / `route:cache`) | File route cache tidak sinkron dari deploy/versi lama (sering krn build campur dua binari PHP CLI — mis. 8.2 & 8.4). **Bukan bug kode** — limiter `login` ada di `routes/web.php:23` | `/opt/alt/php84/usr/bin/php artisan route:clear`, lalu login lagi. Bila ingin cache lagi, ikuti perintah satu-rangkaian & satu binari PHP di §10. Kalau tetap: `php artisan optimize:clear`, cek `tail storage/logs/laravel.log`, ulangi build §10. |
| Perubahan baru tidak muncul | cache lama | `php artisan optimize:clear` lalu `config:cache` + `view:cache` lagi. |
| `php -v` di SSH beda dengan versi web | CLI memakai PHP lain | Pilih versi PHP di hPanel; kalau tetap beda, cari binary php8.3 (mis. `php8.3 artisan ...`) atau tanya Hostinger support. |

## 14. Checklist sukses

- [ ] Buka `https://<DOMAIN>` — halaman login muncul dan tampilannya rapi (CSS/ikon termuat).
- [ ] Login `admin@gentot.local` / `gentot2026` berhasil.
- [ ] **Ganti password** via Pengaturan -> Ubah Password Admin; login ulang dengan password baru.
- [ ] Dashboard: omset, kartu hutang, SKU kritis muncul tanpa error.
- [ ] Data Barang: daftar, kartu stok, cetak barcode (ikon barcode tampil = ekstensi gd OK).
- [ ] Pembelian & Penjualan: simpan transaksi, cetak struk/invoice, bulk print.
- [ ] Generate: jalankan 1 periode kecil, review, simpan.
- [ ] Export: unduh Format A & B (file .xlsx terunduh = ekstensi zip/PhpSpreadsheet OK).
- [ ] Pengaturan: simpan pengaturan toko & NPWP berhasil.

---

## Referensi

- Tutorial deploy Laravel di Hostinger: https://toolmentors.com/how-to-deploy-laravel-project-on-hostinger-shared-hosting/
- Panduan hPanel Hostinger (menu & fitur): https://www.hostinger.com/tutorials/hpanel/ (dan https://elementor.com/blog/a-guide-to-hpanel/)
- Kelola ekstensi/opsi PHP di hPanel: https://www.hostinger.com/support/4667515-como-gerenciar-extensoes-e-opcoes-do-php-no-hpanel-hostinger/ (dokumen resmi, bisa berganti bahasa/URL)
- Akses SSH shared hosting Hostinger: https://support.hostinger.com/ (cari 'SSH Access'); contoh: https://www.hostinger.com/support/10657418-* (menu resmi dapat berubah)
- Sertifikat SSL gratis Hostinger: https://www.hostinger.com/tutorials/como-obtener-un-certificado-ssl/

> Referensi resmi Hostinger dapat berpindah URL — bila tautan mati, cari langsung di https://support.hostinger.com dengan kata kunci sesuai nama menu di atas.