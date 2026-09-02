# AI-HANDOFF — Aplikasi GenTOt (Mechanic Hub)

Dokumen konteks untuk AI / kontributor di sesi lain. Baca ini dulu, lalu gunakan referensi:
- PRD fitur: `docs/PRD-Generate-Transaksi-Otomatis.md`
- Desain visual: `docs/desain/DESIGN.md` + `docs/desain/code.html`
- Setup & deploy: `README.md`

## 1. Ringkasan Proyek

Webapp **Laravel (PHP + MySQL-ready)** untuk toko/bengkel: mencatat pembelian (stok & histori harga), penjualan manual, dan **Generate Transaksi Otomatis** — merekonstruksi penjualan dari **target omset bulanan** dengan syarat **tidak pernah menjual melebihi stok tersedia pada tanggal tsb**, lalu bisa **export siap import ke POS Bengkel**. Target deploy: shared hosting Hostinger (PHP ≥ 8.2 + MySQL). Lokal memakai SQLite (nol konfigurasi).

## 2. Stack & Cara Menjalankan

- Laravel 13, PHP ≥ 8.3, Composer; Vite + Tailwind v4; PhpSpreadsheet; picqer barcode.
- Setup lokal:
  ```bash
  composer install
  cp .env.example .env && php artisan key:generate
  touch database/database.sqlite
  php artisan migrate --seed      # admin + pengaturan + template export + data demo
  npm install
  npm run build                   # atau npm run dev
  php artisan serve
  ```
- Akun admin demo: `admin@gentot.local` / `gentot2026` (1 akun, tanpa registrasi publik).
- Test: `php artisan test` (±115 test, SQLite :memory:) — file di tests/Feature/: Auth, Dashboard, Barang, Supplier, Pembelian, Penjualan, Generate, Export, Harga, Pengaturan, ExcelTemplate, **StokTracking**.
- Produksi: MySQL via `.env` (lihat README untuk langkah Hostinger).

## 3. Arsitektur (folder di `app/`)

- `Enums/` — jenis transaksi (cash|hutang), mode harga (otomatis|manual_terkunci), tipe SN (SN|NON_SN), status SN (tersedia|terjual), sumber transaksi (manual|rekonstruksi), jenis mutasi stok, **StatusPenjualan & StatusBatch: draft|tersimpan|dibatalkan**.
- `Models/` — `TokoSetting` (settings 1 baris + JSON), `Barang`, `HistoriHarga`, `ReferensiSn`, `Supplier`, `InvoicePembelian(+Item)`, `PembayaranHutang`, `KartuStok`, `TransaksiPenjualan(+Item)`, `BatchGenerate`, `TemplateExportPosConfig`, `ExportBatch`.
- `Services/`:
  - `HargaService` — margin (global/override), histori harga per tanggal (`hargaEfektifPada`), toggle mode; **punya cache statis $cacheHarga → selalu `HargaService::resetCache()` di setUp test**.
  - `StockService` — semua mutasi kartu stok + saldo + opname + reversal.
  - `PembelianService` — simpan/edit/hapus invoice atomik, pelunasan cicilan.
  - `PenjualanService` — penjualan manual + commit draft rekonstruksi + **edit nota (rekonsiliasi stok/SN, set diedit_at)**.
  - `GenerateService` — algoritma rekonstruksi (lihat §5).
  - `ExportPosService` / `ExcelImportService` — export Format A/B & import xlsx.
- `Http/Controllers/` — Auth, Dashboard, Barang, Supplier, Pembelian, Penjualan, Generate, Export, Pengaturan, **StokTracking** (tracking stok FIFO), dan **Publik/** (mode publik tanpa login, read-only — lihat §9). Route: `routes/web.php` (semua auth; login throttle 5/menit; CSRF default; session lifetime 120 menit). Semua hapus transaksi & batch via **AJAX** (tanpa redirect) — controller return JSON saat `$request->expectsJson()`, tetap redirect utk non-JS.

## 4. Konvensi Data

- **Uang = integer rupiah** di seluruh DB; helper: `rupiah(50000)` → "Rp 50.000", `uang()` → "50.000", `tanggal_id()`, `bulan_id()` (ada di `app/Support/helpers.php`, diautoload).
- `toko_settings` (1 baris): nama_toko, alamat, telepon, **npwp**, logo, margin_default, jam_buka, jam_tutup, hari_libur {dates[], weekly[]}, ambang_stok_menipis, template_version, parameter_generate JSON:
  `{nota_min, nota_max, toleransi_persen, variasi_min, variasi_max, maks_qty_item_per_nota (default 3), excluded_item_ids[]}`.
- Status transaksi/batch: `draft` → `tersimpan` → (undo) `dibatalkan`. **Semua laporan/omset/export memfilter `status='tersimpan'`**; baris draft/dibatalkan tidak pernah dihitung.
- `diedit_at` pada `transaksi_penjualan`: nota pernah diedit → **batch rekonstruksi tidak bisa di-undo**.

## 5. Alur & Aturan Bisnis Penting

### Pembelian
- Form Cash/Hutang (toggle). Cash: bayar ≥ total (server tolak bila kurang). Hutang: DP + sisa; jatuh tempo auto = akhir bulan ke-3; pelunasan cicilan → lunas saat sisa 0.
- Simpan = tambah stok (kartu_stok masuk) + histori harga (harga_jual dikalkulasi ulang hanya saat mode otomatis).
- Edit/hapus atomik dgn guard: hapus invoice hutang ber-historipembayaran **diblokir**; edit dgn qty baru < qty terjual sejak invoice **ditolak**; hapus yang membuat stok minus → warning 2 opsi (force).

### Penjualan (satu laman "/penjualan")
- Menampilkan **manual + rekonstruksi** (badge Manual/Rekonstruksi + tanda "diedit"); tab filter Semua/Manual/Rekonstruksi.
- **Kartu Omset di atas halaman** (pola dashboard): total omset tersimpan periode + **Sisa Target** (bila ada batch_generate tersimpan yg periode-nya overlap; target dari batch tsb). Filter periode bulan/tahun/rentang (param `bulan/tahun/from/to`, default bulan berjalan) — hanya mempengaruhi kartu, bukan tabel.
- Nota tersimpan (keduanya) bisa **diedit** (tanggal, item, qty, harga) — reversal&reapply stok/SN atomik; rekonstruksi yang diedit → `diedit_at` → **undo batch terkunci**.
- **Hapus via AJAX (tanpa redirect)** — manual & rekonstruksi (diedit ATAU tidak) **boleh dihapus langsung**; guard rekonstruksi sudah dihapus. **Bulk delete** via ceklis (route penjualan.bulk-delete). Draft tidak pernah tampil di halaman ini.
- Cetak struk: `penjualan.print` — header toko (+NPWP bila diisi), tanpa label sumber, footer "*Harga sudah termasuk PPN."; **tidak ada tombol Cetak/Kembali** di view print (pakai Ctrl+P).

### Generate Transaksi (inti fitur)
- Input: periode + target omset; **sisa target = target − transaksi tersimpan di periode** (manual + rekonstruksi).
- Timeline ketersediaan per hari (konservatif: barang masuk hari H baru bisa dijual H+1); **kapasitas = peak availability × harga** (bukan jumlah per-hari).
- Kapasitas < sisa target → **warning 2 opsi** (lanjut semaksimal / batal).
- Distribusi harian proporsional bobot stok masuk; unit-pool fill (SN qty=1 + NO_SN unik; non-SN NO_SN kosong; variasi harga −10%..0%, tidak pernah < harga beli; jam operasional & hari libur dipatuhi).
- **`maks_qty_item_per_nota`** (default 3) membatasi unit per item per nota; pengemasan greedy → **tidak ada barang ganda dalam 1 nota**.
- Ref nota unik global (`refBebas` — baris "dibatalkan" tetap menempati ref).
- Sinkron per request (< 5 detik; ramah shared hosting). Draft tersimpan di DB → review/edit → **Simpan** (potong stok, tandai SN, label rekonstruksi).
- **Undo batch tersimpan**: reversal stok+SN → status `dibatalkan` (baris dipertahankan utk audit); ditolak bila ada nota diedit.
- **Riwayat** (`/generate/riwayat`) urut terbaru di atas (`latest('id')`); batch **draft & dibatalkan** bisa **dihapus** (AJAX, route generate.hapus-batch) — cascade items+transaksi+batch; batch **tersimpan ditolak** (hapus nota via halaman Penjualan, undo via tombol Undo).

### Export / Import Excel
- Format A: kolom dari `template_export_pos_config` (konfigurabel), pre-validasi, **pecah 5.000 baris/file**, sheet PETUNJUK; Format B: laporan + ringkasan; histori + tanda "sudah diupload ke POS". Filter periode export: **rentang tanggal (mulai–selesai) menang atas shortcut bulan**; di UI keduanya saling-eksklusif (input bulan tanpa default, memilih yang satu mengosongkan yang lain).
- Template Data Barang: **prefilled semua SKU existing + dropdown validasi** (SN, STATUS literal; KATEGORI via sheet tersembunyi "Daftar"); import all-or-nothing (`ImportFileException` dgn array errors); lookup KODE_BARANG **case-insensitive** + duplikat dalam file ditolak; STOCK_AWAL hanya utk barang baru.
- Import pembelian dikelompokkan per `REF_PEMBELIAN`; supplier harus sudah terdaftar.

### Tracking Stok (/stok-tracking — Stock Aging Report)
- Tujuan: lihat stok yang masih tersisa dari batch pembelian per bulan, utk prioritas menghabiskan stok terlama (FIFO) via penjualan manual.
- **Algoritma**: per barang dgn stock > 0, ambil semua kartu_stok masuk (masuk_pembelian, stok_awal, opname positif) + keluar (keluar_penjualan); simulasi FIFO — tiap keluar menghabiskan batch paling tua; sisa per batch = qty asli − yg dikonsumsi. Batch dgn sisa > 0 ditampilkan.
- View: **Ringkasan Total Stok Keseluruhan** (luar filter — query `Barang::sum('stock')`) + **filter bulan** (checkbox; default semua bulan) + tabel per bulan (SKU, kode, sumber Pembelian/Stok Awal/Penyesuaian, tgl masuk, qty awal, sisa, umur hari).
- Catatan: opname bisa positif/negatif; pemfilteran & tampilan tabel berbasis bulan dari batch tersisa.

## 6. Jebakan Teknis yang Diketahui

- **PhpSpreadsheet v5**: validasi memakai `PhpOffice\PhpSpreadsheet\Cell\DataValidation` (+ `setSqref` lalu `setDataValidation`); `setShowDropDown(false)` = panah tampil (konvensi terbalik); `setColumnWidth` tidak ada → pakai `getColumnDimension()->setWidth()`.
- Cache statis `HargaService::$cacheHarga` bocor antar-test → reset di setUp.
- Instance model bisa **stale** setelah reversal stok → `->fresh()` sebelum mutasi.
- String PHP dgn backslash utk formula Excel (mis. "$A$1") mudah error escaping; tulis hati-hati.
- Timezone app `Asia/Jakarta`; `date` shell bisa beda dgn `now()` Laravel saat scripting.
- Tambah status baru → pastikan semua query laporan tetap filter `tersimpan`.
- **Urutan FK migration (MySQL vs SQLite)**: FK yang menunjuk ke tabel yang dibuat oleh migration dgn timestamp *belakangan* GAGAL di MySQL/MariaDB (error 1005 "Foreign key constraint is incorrectly formed") tapi lolos diam-diam di SQLite dev (FK tak di-enforce). Contoh historis: histori_harga, referensi_sn, kartu_stok menunjuk ke invoice_pembelian & transaksi_penjualan. **Aturan**: definisikan kolom FK *tanpa constraint* di migration asli (cukup foreignId(...)->nullable()), lalu tambahkan constraint-nya di *migration terpisah* setelah kedua tabel ada (lihat 2026_09_06_000001..000004: Schema::table(...) + $table->foreign(...) + ->nullOnDelete()). SQLite mendukung Schema::table()->foreign()/dropForeign via strategi rebuild tabel, jadi migrate:fresh lokal tetap hijau — tapi wajib diverifikasi di MySQL sebelum deploy.

## 7. Checklist Menambah Fitur

1. Ikuti pola: Services (logika + transaksi) → Controller (validasi + flash) → route bernama → Blade (komponen/partial) → test `tests/Feature`.
2. Jaga aturan "all-or-nothing" & status filter; uji alur ganda (Cash/Hutang, draft/tersimpan/batal).
3. `php artisan test` hijau; smoke via curl/browser; update README & dokumen ini bila aturan bisnis berubah.

## 8. Status Fitur (per Agustus–Sept 2026)

**Selesai:** login+throttle, dashboard (periode omset bulan/tahun/rentang, kartu hutang & stok, transaksi terakhir dipisah, barang terbaru), Data Barang (template prefilled+dropdown, import, update harga, kartu stok/opname, barcode), Supplier, **Tracking Stok** (stock aging FIFO, ringkasan total + filter bulan), Pembelian (Cash/Hutang, pelunasan, import excel, tab nav dalam halaman, **bulk print/cetak terpilih**, **AJAX delete + bulk delete**), Penjualan (satu laman, edit nota, struk cetak+NPWP+footer PPN, **bulk print**, **AJAX delete + bulk delete**, **kartu omset + sisa target + filter periode**), Generate (draft→review→simpan, undo batch, hapus batch draft/dibatalkan di Riwayat, rekonstruksi bisa dihapus langsung), Export A/B + histori (tombol hapus per item, filter tanggal yang benar), Pengaturan (NPWP, parameter generate, ubah password admin, fix libur_dates), **Dark mode toggle** (gelap/terang), **Mode Publik tanpa login** (read-only di `/publik`, §9).

**Belum/tunda:** tombol "Cetak/Kembali" di view print sengaja dihapus; penggantian footer lama menjadi "*Harga sudah termasuk PPN." sudah diterapkan di penjualan & pembelian print; untuk mode publik: **PIN akses opsional** bila data dianggap terlalu terbuka & **tautan "Lihat Mode Publik"** dari sidebar admin (belum dikerjakan).

**Bulk print (cetak terpilih):** di halaman Penjualan, Data Hutang Belum Lunas, dan Data Pembelian (Lunas/Cash) ada kolom ceklis per baris + tombol "Cetak Terpilih (N)" di toolbar. Submit POST route print-batch (penjualan.print-batch / pembelian.print-batch) dengan ids[]; server memvalidasi (min 1 id, integer; id tak dikenal dibuang diam-diam; penjualan hanya status=tersimpan; tanpa id valid → flash error) lalu membuka satu halaman cetak berisi semua dokumen terpilih (class .print-page, break-inside: avoid via @media print; tiap nota diberi padding vertikal 24px atas-bawah agar tidak menempel — berlaku di kedua mode page break). Halaman cetak punya toggle **"Halaman baru per nota"** (partial partials.bulk-print-toggle; default aktif, tanpa JS tetap page break per nota karena .pb-off mematikan page-break-after via CSS; kontrol non-print di pojok kanan atas, disembunyikan saat print via @media print). Markup dokumen dipakai bersama via partial penjualan.partials.struk & pembelian.partials.invoice; tab baru via target="_blank" (gestur klik → lolos popup-blocker), cetak dengan Ctrl+P.

**Keputusan penting:** navigasi Pembelian via tab bar di dalam halaman (bukan submenu sidebar); item "Penjualan Manual" diganti "Penjualan" (satu laman); **hapus nota/barang/batch via AJAX** (tanpa redirect — row langsung hilang, fallback redirect utk non-JS); **dark mode**: toggle di sidebar (body/.dark pada <html>, localStorage 'theme', default prefers-color-scheme, CSS overrides variabel @theme, cetak selalu terang); edit nota rekonstruksi mengunci undo batch tapi nota tsb boleh dihapus langsung (guard rekonstruksi dihapus); **sidebar**: desktop minimize (rail ikon 64px, localStorage via body.sidebar-minimized), mobile drawer tanpa backdrop-blur (sidebar z-40 di atas overlay z-30).

## 9. Mode Publik (tanpa login, read-only)

**Tujuan:** versi tampilan tanpa autentikasi — seluruh menu read-only (mis. layar display/kiosk atau akses tamu). User hanya melihat data, tidak ada aksi mutasi apa pun.

- **Route:** grup `Route::prefix('publik')->name('publik.')->group(...)` di `routes/web.php`, **tanpa middleware auth** (hanya grup `web`). Nama route = `publik.` + nama admin yg sama persis (mis. `publik.barang.kartuStok`, `publik.pembelian.invoice-hutang`) supaya pola closure `$r` generik.
- **Cakupan:** Dashboard (/publik), Data Barang (index/show/kartu-stok/barcode), Tracking Stok, Pembelian (hutang/lunas/show/print), Penjualan (index/print). **Tidak ada route** untuk Supplier, Generate, Export, Pengaturan → akses langsung `/publik/supplier` dst. = **404** (bukan login, bukan halaman kosong).
- **Controller:** `app/Http/Controllers/Publik/*` — read-only; menyalin query controller admin dan me-render **view admin yang sama** dengan variabel tambahan `'publik' => true`. Tanpa method create/store/update/destroy/import/bayar/bulk.
- **Pola view (guard `$publik`):** tiap `@section('content')` yang terdampak dimulai dengan
  `@php($publik = $publik ?? false)` dan `@php($r = fn (string $n, ...$p) => route(($publik ? 'publik.' : '') . $n, ...$p))`.
  - Tombol/link mutasi admin (tambah/edit/hapus/import/template/opname/stok-awal/bayar/bulk print/bulk delete/CTA dashboard) dibungkus `@if(! $publik) ... @endif` (tetap `route()` polos).
  - Link lintas-mode (index/show/print/kartuStok/barcode/tab pembelian/filter stok-tracking) memakai `$r(...)` → di admin = `route('...')`, di publik = `route('publik....')`.
  - Layout `layouts/app.blade.php`: sidebar hanya 5 menu + link **Login** (bukan Logout), chip header "Mode Publik", subjudul brand "Mode Publik — Lihat Saja", `<meta name="robots" content="noindex, nofollow">`.
- **Aturan konten publik:** label sumber (Manual/Rekonstruksi) & badge "diedit" **disembunyikan** (dashboard + penjualan); Penjualan publik = **hanya daftar transaksi** (tanpa kartu omset/target, tanpa tab sumber, tanpa kolom Sumber, tanpa edit — detail expand & cetak struk tetap ada); dashboard tanpa kartu "Aksi Cepat", tanpa CTA "Bayar Sekarang/Order Sekarang", tanpa baris "Sumber Transaksi"; pencarian/show-entries/paginasi tetap aktif.
- **Jebakan yang sudah dikenal:**
  - `auth()->user()` bisa null saat guest → layout memakai null-safe `auth()->user()?->name` dan cabang `@if(! $publik)`.
  - Menambahkan route publik baru → nama Wajib mengikuti nama admin (`publik.` + nama yg sama) agar `$r` bekerja.
  - Di test, `assertSee('title="..."')` butuh argumen kedua `false` (escaping HTML default).
  - Kolom ceklis & kolom Sumber disembunyikan → `colspan` baris detail/empty dibuat dinamis (`{{ $publik ? 5 : 7 }}`).
- **Tidak menyentuh CSS/JS** (murni Blade+PHP) → tanpa `npm run build`; serangan ke route mutasi tetap tertahan middleware auth (guest di-redirect ke /login).
- **Ide tambahan (belum dikerjakan):** PIN akses opsional utk mode publik; tautan "Lihat Mode Publik" dari sidebar admin; halaman landing publik terpisah.

