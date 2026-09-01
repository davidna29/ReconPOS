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
- Test: `php artisan test` (±73 test, SQLite :memory:).
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
- `Http/Controllers/` — Auth, Dashboard, Barang, Supplier, Pembelian, Penjualan, Generate, Export, Pengaturan. Route: `routes/web.php` (semua auth; login throttle 5/menit; CSRF default; session lifetime 120 menit).

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
- Nota tersimpan (keduanya) bisa **diedit** (tanggal, item, qty, harga) — reversal&reapply stok/SN atomik; rekonstruksi yang diedit → `diedit_at` → **undo batch terkunci**.
- Hapus hanya untuk manual (rekonstruksi lewat undo batch).
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

### Export / Import Excel
- Format A: kolom dari `template_export_pos_config` (konfigurabel), pre-validasi, **pecah 5.000 baris/file**, sheet PETUNJUK; Format B: laporan + ringkasan; histori + tanda "sudah diupload ke POS". Filter periode export: **rentang tanggal (mulai–selesai) menang atas shortcut bulan**; di UI keduanya saling-eksklusif (input bulan tanpa default, memilih yang satu mengosongkan yang lain).
- Template Data Barang: **prefilled semua SKU existing + dropdown validasi** (SN, STATUS literal; KATEGORI via sheet tersembunyi "Daftar"); import all-or-nothing (`ImportFileException` dgn array errors); lookup KODE_BARANG **case-insensitive** + duplikat dalam file ditolak; STOCK_AWAL hanya utk barang baru.
- Import pembelian dikelompokkan per `REF_PEMBELIAN`; supplier harus sudah terdaftar.

## 6. Jebakan Teknis yang Diketahui

- **PhpSpreadsheet v5**: validasi memakai `PhpOffice\PhpSpreadsheet\Cell\DataValidation` (+ `setSqref` lalu `setDataValidation`); `setShowDropDown(false)` = panah tampil (konvensi terbalik); `setColumnWidth` tidak ada → pakai `getColumnDimension()->setWidth()`.
- Cache statis `HargaService::$cacheHarga` bocor antar-test → reset di setUp.
- Instance model bisa **stale** setelah reversal stok → `->fresh()` sebelum mutasi.
- String PHP dgn backslash utk formula Excel (mis. "$A$1") mudah error escaping; tulis hati-hati.
- Timezone app `Asia/Jakarta`; `date` shell bisa beda dgn `now()` Laravel saat scripting.
- Tambah status baru → pastikan semua query laporan tetap filter `tersimpan`.

## 7. Checklist Menambah Fitur

1. Ikuti pola: Services (logika + transaksi) → Controller (validasi + flash) → route bernama → Blade (komponen/partial) → test `tests/Feature`.
2. Jaga aturan "all-or-nothing" & status filter; uji alur ganda (Cash/Hutang, draft/tersimpan/batal).
3. `php artisan test` hijau; smoke via curl/browser; update README & dokumen ini bila aturan bisnis berubah.

## 8. Status Fitur (per Agustus–Sept 2026)

**Selesai:** login+throttle, dashboard (periode omset bulan/tahun/rentang, kartu hutang & stok, transaksi terakhir dipisah, barang terbaru), Data Barang (template prefilled+dropdown, import, update harga, kartu stok/opname, barcode), Supplier, Pembelian (Cash/Hutang, pelunasan, import excel, tab nav dalam halaman, **bulk print/cetak terpilih**), Penjualan (satu laman, edit nota, struk cetak+NPWP+footer PPN, **bulk print/cetak terpilih**), Generate (draft→review→simpan, undo batch + terkunci saat diedit, detail nota per ref, maks qty per item per nota), Export A/B + histori (tombol hapus per item riwayat — baris + file XLSX ikut dihapus; batch berstatus "sudah diupload" tetap bisa dihapus), Pengaturan (termasuk NPWP, parameter generate, & **ubah password admin**).

**Belum/tunda:** tombol "Cetak/Kembali" di view print sengaja dihapus; penggantian footer lama menjadi "*Harga sudah termasuk PPN." sudah diterapkan di penjualan & pembelian print.

**Bulk print (cetak terpilih):** di halaman Penjualan, Data Hutang Belum Lunas, dan Data Pembelian (Lunas/Cash) ada kolom ceklis per baris + tombol "Cetak Terpilih (N)" di toolbar. Submit POST route print-batch (penjualan.print-batch / pembelian.print-batch) dengan ids[]; server memvalidasi (min 1 id, integer; id tak dikenal dibuang diam-diam; penjualan hanya status=tersimpan; tanpa id valid → flash error) lalu membuka satu halaman cetak berisi semua dokumen terpilih (class .print-page, break-inside: avoid via @media print; tiap nota diberi padding vertikal 24px atas-bawah agar tidak menempel — berlaku di kedua mode page break). Halaman cetak punya toggle **"Halaman baru per nota"** (partial partials.bulk-print-toggle; default aktif, tanpa JS tetap page break per nota karena .pb-off mematikan page-break-after via CSS; kontrol non-print di pojok kanan atas, disembunyikan saat print via @media print). Markup dokumen dipakai bersama via partial penjualan.partials.struk & pembelian.partials.invoice; tab baru via target="_blank" (gestur klik → lolos popup-blocker), cetak dengan Ctrl+P.

**Keputusan penting:** navigasi Pembelian via tab bar di dalam halaman (bukan submenu sidebar); item "Penjualan Manual" diganti "Penjualan" (satu laman); edit nota rekonstruksi otomatis mengunci undo batch; **sidebar**: desktop bisa di-minimize (rail ikon 64px, toggle hamburger di header, state tersimpan di localStorage via body.sidebar-minimized), mobile terbuka sebagai drawer tanpa backdrop-blur (sidebar z-40 di atas overlay z-30).
