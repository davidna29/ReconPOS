# Aplikasi GenTOt — Generate Transaksi Otomatis (Mechanic Hub)

Web app **Laravel (PHP + MySQL-ready)** untuk rekonstruksi penjualan berbasis **target omset & stok masuk**,
s sesuai PRD `docs/PRD-Generate-Transaksi-Otomatis.md`, dengan desain mengikuti `docs/desain/DESIGN.md`
& referensi UI `docs/desain/code.html` (Material Design, Inter + JetBrains Mono, warna semantik Cash/Hutang/Lunas/Danger).

> Target deploy: **shared hosting Hostinger** (PHP 8.2+ + MySQL). Local dev memakai SQLite agar nol-konfigurasi;
> seluruh kode DB-agnostic via Eloquent - tinggal ganti `.env` untuk produksi.

---

## Fitur (peta ke PRD)

| Modul | Ringkas | PRD |
|---|---|---|
| Login 1 admin (tanpa registrasi publik) | throttle brute-force, session timeout 2 jam | 1.1, 1.2, 9 |
| Dashboard | omset bulan berjalan (manual + rekonstruksi), hutang + jatuh tempo <=7 hari, SKU kritis, aksi cepat, transaksi terakhir | 1.2b |
| Data Barang | master SKU, template import/export Excel, Update Harga Excel, Kartu Stok (satu-satunya jalur Stok Awal & Opname wajib alasan), Cetak Barcode, margin override + mode otomatis/kunci | 4.1, 4.3 |
| Data Supplier | CRUD + modal ringkas, total hutang berjalan, riwayat pembelian | 4.4 |
| Pembelian | form Transaksi (tab Cash / Hutang, Diskon Item + Diskon Total), Invoice Belum Lunas/Lunas, pelunasan cicilan, import Excel (template dgn **dropdown supplier** + panduan PETUNJUK), edit/hapus atomik (5.5, warning stok minus dgn opsi force di AJAX & non-JS), **bulk print / cetak terpilih** | 5 |
| Penjualan Manual (kasir-lite) | input manual (CUSTOMER_ID = 0) sebagai sumber `manual` untuk agregasi & export, **bulk print / cetak terpilih** | 1.2b, 8 |
| **Generate Transaksi** | periode + target omset -> draft per hari -> review/edit -> **Simpan** (potong stok, tandai SN, label `rekonstruksi`). Kapasitas stok kurang -> warning 2 opsi. Hari libur & jam operasional dipatuhi. **Item Dikecualikan** (checklist + pencarian, default dari Pengaturan) | 6, 7, 9 |
| Export | **Format A** siap import POS Bengkel (template konfigurabel, pre-validasi, pecah 5.000 baris/file, sheet PETUNJUK) & **Format B** laporan internal + ringkasan; histori & tanda 'sudah diupload ke POS' | 4.2, 8 |
| Pengaturan Toko | info toko, margin global, jam/hari operasional, parameter generate, ambang stok, **Template Export POS**, **ubah password admin** | 4.5, 4.2 |
| **Mode Publik (tanpa login, read-only)** | akses `/publik` tanpa login: Dashboard, Data Barang, Tracking Stok, Pembelian, Penjualan — semua read-only, tanpa Supplier/Generate/Export/Pengaturan, tanpa edit/template/bulk & tanpa label sumber | tambahan |

---

## Setup Lokal (macOS/Linux/Windows + PHP >= 8.3)

    composer install
    cp .env.example .env
    php artisan key:generate
    touch database/database.sqlite          # bila belum ada
    php artisan migrate --seed              # admin + pengaturan + template export + data demo
    npm install
    npm run build                           # atau npm run dev utk hot reload
    php artisan serve                       # http://localhost:8000

Buka `http://localhost:8000` lalu login:

| | |
|---|---|
| **Email** | `admin@gentot.local` |
| **Password** | `gentot2026` |

> Ganti password lewat `php artisan tinker`:
>     App\Models\User::first()->update(['password' => 'baru!']);
>
> Data demo dibuat oleh `Database\Seeders\DemoSeeder` - hapus pemanggilannya di `DatabaseSeeder` bila ingin DB bersih.

### Menjalankan test

    php artisan test        # 130 test, 600+ assertions (SQLite :memory:)

### Mode Publik (tanpa login, read-only)

Buka `http://localhost:8000/publik` **tanpa login** — versi tampilan (read-only) seluruh modul terpilih:

- Tampil: **Dashboard, Data Barang** (+ detail, kartu stok, cetak barcode), **Tracking Stok**, **Pembelian** (Hutang Belum Lunas / Lunas-Cash / detail + cetak invoice), **Penjualan** (daftar transaksi + cetak struk).
- Semua halaman **read-only**: tanpa tombol tambah/edit/hapus/import/opname/bayar, tanpa bulk print & bulk delete.
- Menu **Data Supplier, Generate Transaksi, Export, Pengaturan tidak tersedia** di publik → akses langsung `/publik/supplier` dst. = **404**.
- Label sumber (Manual/Rekonstruksi), kartu omset/target, dan tab filter sumber **disembunyikan** di publik.
- Halaman publik diberi `<meta name="robots" content="noindex, nofollow">`; sidebar menampilkan tautan **Login** untuk kembali ke mode admin.

---

## Deploy ke Hostinger (shared hosting)

> 🚀 **Pemula? Baca panduan langkah demi langkah:** [docs/DEPLOY-HOSTINGER.md](docs/DEPLOY-HOSTINGER.md) — versi ringkas di bawah.

1. **Upload** seluruh folder proyek (kecuali `node_modules`, `vendor`) ke server, lalu **pindahkan isi `public/` ke `public_html/`**
   dan sesuaikan bootstrap path di `public/index.php` (mis. proyek di `~/domains/situs/public_html/app` ->
   autoload `../app/vendor/autoload.php`). `.env` otomatis berada di luar folder publik (keamanan default Laravel).

2. **Buat database MySQL** dari panel (mis. `db_gentot`, user `u_gentot`, password kuat).

3. **`.env` produksi**:

        APP_ENV=production
        APP_DEBUG=false
        APP_URL=https://situs-anda.id
        DB_CONNECTION=mysql
        DB_HOST=127.0.0.1
        DB_PORT=3306
        DB_DATABASE=db_gentot
        DB_USERNAME=u_gentot
        DB_PASSWORD=***RAHASIA***
        SESSION_DRIVER=database
        CACHE_STORE=database
        QUEUE_CONNECTION=database

4. **Install** (via SSH / paket Composer di panel):

        composer install --no-dev --optimize-autoloader
        php artisan key:generate
        php artisan migrate --seed --force
        php artisan storage:link
        php artisan config:cache route:cache view:cache

5. **HTTPS / SSL**: aktifkan SSL gratis (Let's Encrypt) di panel Hostinger; pastikan `APP_URL` https.

6. **Backup berkala** (CRON panel):

        mysqldump -u u_gentot -p'***' db_gentot | gzip > ~/backup/gentot-$(date +%F).sql.gz

7. **Catatan performa shared hosting**:
   - Generate transaksi berjalan **sinkron per request** (< 5 detik utk 1 bulan; sesuai `max_execution_time`).
   - Export diproses berbatas (pecah 5.000 baris/file) untuk menghindari timeout.

---

## Arsitektur Ringkas

    app/
      Enums/            # jenis transaksi, mode harga, tipe SN, status batch, dll.
      Models/           # 16+ model (skema PRD bab 12)
      Services/
        HargaService        # margin global/override, histori harga, harga efektif per tanggal (4.1)
        StockService        # mutasi kartu stok, saldo, opname, reversal (4.3b, 5.4, 5.5)
        PembelianService    # simpan/edit/hapus pembelian atomik, pelunasan (5)
        PenjualanService    # penjualan manual + commit draft rekonstruksi (7)
        GenerateService     # algoritma (6): timeline stok, unit-pool, alokasi bobot, toleransi, hari libur, SN
        ExportPosService    # Format A konfigurabel + chunking + pre-validasi; Format B (8)
        ExcelImportService  # import master barang & pembelian (all-or-nothing) (4.3c, 5.3)
    app/Http/Controllers/ # Auth, Dashboard, Barang, Supplier, Pembelian, Penjualan, Generate, Export, Pengaturan
                          Publik/   # mode publik tanpa login (read-only): Dashboard, Barang, Pembelian, Penjualan, StokTracking
    routes/web.php        # semua route, throttle login
    resources/views/      # Blade + design system Tailwind v4 (token dari DESIGN.md)
    database/migrations|seeders
    tests/Feature/        # 130 test end-to-end (termasuk PublikTest)

**Algoritma Generate (PRD 6):** sisa target = target - transaksi tersimpan di periode -> timeline ketersediaan
stok per hari (running balance, konsumsi simulasi carry-forward) -> cek kapasitas (warning) -> alokasi harian
proporsional stok masuk dengan jitter natural -> unit-pool fill (SN qty=1 + NO_SN unik, non-SN NO_SN kosong,
variasi harga -10%..0% tidak pernah di bawah harga beli, tidak ada transaksi di hari libur, jam dalam jam
operasional) -> penyesuaian akhir (tuning harga dalam rentang variasi / qty / top-up / drop) agar total dalam
toleransi -> **draft reversibel** -> review/edit -> Simpan (potong stok & tandai SN, atomik).

---

## Keputusan Implementasi (ringkas)

- Semua angka uang disimpan **integer rupiah**; sebagian besar konfigurasi disimpan di DB
  (`toko_settings` + `template_export_pos_config`), bukan hardcode (4.2, 4.5).
- `CUSTOMER_ID = 0` (Customer Umum) utk semua transaksi hasil generate (4.2).
- Draft generate tersimpan di DB (status `draft`) sehingga bisa di-refresh/review; efek stok hanya
  diterapkan saat `Simpan` (status `tersimpan`) - reversible (PRD 9).
- Generate ulang periode sama = menimpa draft sebelumnya setelah konfirmasi (aturan keras #3).
- Penjualan manual dibuat minimal ('kasir-lite') sebagai sumber `manual` utk agregasi dashboard/export.
- Local dev SQLite; produksi MySQL via `.env` (lihat di atas).
