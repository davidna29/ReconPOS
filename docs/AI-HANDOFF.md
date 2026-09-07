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
  - `HargaService` — margin (global/override), histori harga per tanggal (`hargaEfektifPada`), toggle mode, **`terapkanMarginDefaultKeSemua()`** (re-kalkulasi harga jual SKU otomatis tanpa override — dipakai tombol di Pengaturan); **punya cache statis $cacheHarga → selalu `HargaService::resetCache()` di setUp test**.
  - `StockService` — semua mutasi kartu stok + saldo + opname + reversal.
  - `PembelianService` — simpan/edit/hapus invoice atomik, pelunasan cicilan.
  - `PenjualanService` — penjualan manual + commit draft rekonstruksi + **edit nota (rekonsiliasi stok/SN, set diedit_at)**.
  - `GenerateService` — algoritma rekonstruksi (lihat §5).
  - `ExportPosService` / `ExcelImportService` — export Format A/B & import xlsx.
- `Http/Controllers/` — Auth, Dashboard, Barang, Supplier, Pembelian, Penjualan, Generate, Export, Pengaturan, **StokTracking** (tracking stok FIFO), dan **Publik/** (mode publik tanpa login, read-only — lihat §9). Route: `routes/web.php` (semua auth; login throttle 5/menit; CSRF default; session lifetime 120 menit). Semua hapus transaksi & batch via **AJAX** (tanpa redirect) — controller return JSON saat `$request->expectsJson()`, tetap redirect utk non-JS.

## 4. Konvensi Data

- **Uang = integer rupiah** di seluruh DB; helper: `rupiah(50000)` → "Rp 50.000", `uang()` → "50.000", `tanggal_id()`, `bulan_id()` (ada di `app/Support/helpers.php`, diautoload).
- `toko_settings` (1 baris): nama_toko, alamat, telepon, **npwp**, logo, margin_default, jam_buka, jam_tutup, hari_libur {dates[], weekly[]}, ambang_stok_menipis, template_version, parameter_generate JSON:
  `{nota_min (1–100), nota_max (1–100), toleransi_persen, variasi_min, variasi_max, maks_qty_item_per_nota (default 3), maks_sku_per_nota (default 1), excluded_item_ids[]}` — `nota_max` 100 memungkinkan target besar (1.2B) tercapai (≈100 nota/hari ×30 hari).
- Status transaksi/batch: `draft` → `tersimpan` → (undo) `dibatalkan`. **Semua laporan/omset/export memfilter `status='tersimpan'`**; baris draft/dibatalkan tidak pernah dihitung.
- `diedit_at` pada `transaksi_penjualan`: nota pernah diedit → **batch rekonstruksi tidak bisa di-undo**.

## 5. Alur & Aturan Bisnis Penting

### Pembelian
- Form Cash/Hutang (toggle). Cash: bayar ≥ total + PPN (server tolak bila kurang). Hutang: DP + sisa; jatuh tempo auto = akhir bulan ke-3; pelunasan cicilan → lunas saat sisa 0. **PPN otomatis + override** (`invoice_pembelian.ppn` integer Rp; tarif `toko_settings.ppn_persen` default 11, diubah di Pengaturan): form ada checkbox **PPN otomatis (default centang)** → `PPN = round((Subtotal − Diskon) × tarif/100)` dihitung live via JS & ulang di server (`PembelianService::hitungPpnOtomatis`); hapus centang → input manual dipakai (koreksi e-Faktur, 0 = tanpa PPN). Formula **Sisa Hutang = (Subtotal − Diskon) + PPN − DP**; baris "PPN" tampil di detail & print hanya bila > 0; ikut dihitung saat Edit & di Kembali cash (bayar − (total+PPN)). Edit: status auto awal = centang bila PPN tersimpan == hasil auto DPP saat ini. **Alokasi PPN ke HPP**: item & subtotal invoice tetap memakai harga NETO, tapi **histori harga / `harga_beli_terakhir` / margin & harga jual memakai "harga beli efektif" = neto + bagian PPN** — mode auto/manual invoice-level dialokasikan proporsional per nilai baris (`round(ppn×sub_i/subtotal)` lalu dibagi qty); mode override per-SKU (Excel) memakai PPN baris langsung (`neto + round(ppn_baris/qty)`), sehingga HPP & margin otomatis sudah termasuk PPN. **Import excel kolom PPN (opsional, kolom terakhir) = nominal Rp PER BARIS SKU**: total invoice = **Σ PPN baris** dalam 1 REF (baris kosong = 0); bila **semua baris kosong/0 → otomatis** `round(DPP × tarif)`; PPN negatif ditolak; utk CASH dgn BAYAR_DP kosong, bayar pas = total + PPN (auto/sum). Kolom `invoice_pembelian_item.ppn` menyimpan PPN per baris utk audit. **Metadata invoice per grup REF memakai aturan "nilai pertama yang terisi"** (BAYAR_DP, DISKON_TOTAL, JATUH_TEMPO cukup diisi SEKALI di salah satu baris grup — PPN dikecualikan karena per-SKU). **Jebakan PHP**: setelah `$m = &$groups[$ref]['metadata']` di loop bayar-pas, WAJIB `unset($m)` — kalau tidak, assignment `$m = ...` di loop berikutnya menimpa metadata grup LAIN lewat referensi (bug nyata: grup ke-2 HUTANG/TRIJAYA berubah jadi CASH/PRIMA). **Stok yatim (orphan)**: baris `kartu_stok` jenis `masuk_pembelian` dengan `invoice_pembelian_id = NULL` (keterangan tetap "Pembelian {no_invoice}") TIDAK ikut ter-reverse saat invoice dihapus (`batalEfekPembelian` mencari by invoice id) → stok tertinggal di /barang; muncul bila invoice dihapus di luar alur `PembelianService::hapus` & FK MySQL `nullOnDelete` (SET NULL) men-null-kan kolom tsb. Script `cek-anomali-stok.php` section 4/5/6 mengidentifikasi & mengestimasi stok bersih. **Koreksi**: `scripts/bersihkan-stok-yatim.php` (dry-run default; `--apply` utk eksekusi) — baris yatim di-set qty=0 (tetap ada utk audit, keterangan "(stok yatim dibersihkan)") lalu `barang.stock = SUM(kartu tersisa)` per SKU terdampak.
- Simpan = tambah stok (kartu_stok masuk) + histori harga (harga_jual dikalkulasi ulang hanya saat mode otomatis).
- Edit/hapus atomik dgn guard: hapus invoice hutang ber-historipembayaran **diblokir**; edit dgn qty baru < qty terjual sejak invoice **ditolak**; hapus yang membuat stok minus → warning 2 opsi (force) — **valid di kedua jalur**: non-JS (modal partial resolve-stok-warning) & AJAX (confirm "Lanjutkan/Batalkan" → kirim ulang force=1, lihat app.js). Pesan sukses force menyebut stok minus utk investigasi. **Cek warning hapus dihitung PER SKU (agregat total qty semua baris barang yang sama), bukan per baris** — cek per-baris bisa lolos diam-diam & reversal (yag mengurangi total) membuat stok minus besar tanpa warning (contoh: SKU ganda 4×6=24, stok 10 → hapus → −14).
- **Audit stok**: `scripts/cek-anomali-stok.php` (read-only, jalan `php scripts/cek-anomali-stok.php`) mencetak: (1) barang stok<0 + invoice terkait + 10 kartu terakhir, (2) invoice SKU-ganda + **stok saat ini SKU tsb** (aman/tidak utk dihapus), (3) **drift semua barang** (`barang.stock` vs `SUM(kartu_stok.qty)`) — utk investigasi stok minus & konsistensi data. **Koreksi drift satu-kali**: `scripts/koreksi-kartu-drift-2026-09.php` (dry-run default, `--apply` eksekusi; guard abort bila drift ≠ ekspektasi) — menambah baris kartu `keluar_penjualan` TANPA ubah kolom stock (kolom = fisik, kartu kelebihan krn keluar tanpa nota); dipakai utk 2 SKU Sep 2026 (BL-20570R15-CHA-ECO −13, PCR-18570R14-CHA-ECO −14). Guard hapus invoice memakai kolom `stock`, jadi drift kolom-salah memicu warning palsu — pastikan kolom = fisik dulu sebelum percaya warning.
- **Filter list:** Hutang (`invoice-hutang`) & Lunas (`invoice-lunas`) memiliki filter periode (bulan/tahun/rentang `bulan/tahun/from/to`, prioritas bulan > tahun > rentang) + filter supplier dropdown, berlaku di admin **dan** di `/publik/pembelian/*` (read-only, tanpa bulk/bayar/edit).

### Penjualan (satu laman "/penjualan")
- Menampilkan **manual + rekonstruksi** (badge Manual/Rekonstruksi + tanda "diedit"); tab filter Semua/Manual/Rekonstruksi.
- **Kartu Omset di atas halaman** (pola dashboard): total omset tersimpan periode + **Sisa Target** (bila ada batch_generate tersimpan yg periode-nya overlap; target dari batch tsb). Filter periode bulan/tahun/rentang (param `bulan/tahun/from/to`, default bulan berjalan) — **kartu selalu Bulan Berjalan bila tanpa query; tabel hanya terfilter bila ada query periode eksplisit (tanpa query = Semua, agar data Juni tetap terlihat), bila ada filter keduanya sinkron** (`whereBetween tanggal_waktu`). **Kartu + filter kini juga tampil di `/publik/penjualan` (read-only, tanpa tab sumber).**
- Nota tersimpan (keduanya) bisa **diedit** (tanggal, item, qty, harga) — reversal&reapply stok/SN atomik; rekonstruksi yang diedit → `diedit_at` → **undo batch terkunci**.
- **Hapus via AJAX (tanpa redirect)** — manual & rekonstruksi (diedit ATAU tidak) **boleh dihapus langsung**; guard rekonstruksi sudah dihapus. **Bulk delete** via ceklis (route penjualan.bulk-delete). Draft tidak pernah tampil di halaman ini.
- Cetak struk: `penjualan.print` — header toko (+NPWP bila diisi), tanpa label sumber, footer "*Harga sudah termasuk PPN."; **tidak ada tombol Cetak/Kembali** di view print (pakai Ctrl+P).

### Generate Transaksi (inti fitur)
- Input: periode + target omset; **sisa target = target − transaksi tersimpan di periode** (manual + rekonstruksi).
- Timeline ketersediaan per hari (konservatif: barang masuk hari H baru bisa dijual H+1; **stok awal sebelum periode dihitung sebagai `initialBalance`**); **kapasitas = peak availability × harga** (peak = max stok di awal hari, `final` untuk diagnosa) — `Log::debug` mencatat `kapasitasPeak/Final`, `totalWeight`, `dayTargets`.
- Kapasitas < sisa target → **warning 2 opsi** (lanjut semaksimal / batal).
- Distribusi harian **per-hari proporsional bobot stok tersedia (`avail`), bukan stok masuk**, dengan jitter ±20%; **qty acak 1..`maks_qty`** (bukan `floor(sisa/price)` deterministik) + unit-pool fill (SN qty=1 + NO_SN unik; non-SN NO_SN kosong; variasi harga −10%..0%, tidak pernah < harga beli; jam operasional & hari libur dipatuhi); **global fill** untuk sisa target & `penyesuaianAkhir` agresif (batch top-up, iterasi 300). **Fix penting:** `hariBatas` habis tidak lagi `unitSisa=-1` global (hanya `soldToday` per hari), sehingga tidak ada hari lompat.
- **Basis harga generate = `barang.harga_jual` (MASTER)** — harga jual efektif dari histori_harga TIDAK dipakai utk basis generate (tombol "Terapkan Margin" di Pengaturan yang meng-update master langsung memengaruhi draft generate; kapasitas & peek juga master); variasi & floor ≥ harga beli (`harga_beli_terakhir`, termasuk alokasi PPN) tetap. Histori tetap utk audit/export modul lain.
- **`maks_qty_item_per_nota`** (default 3) membatasi unit 1 SKU dalam 1 nota (mis. 4 = 1 nota bisa 1-4 ban); **`maks_sku_per_nota`** (default 1) membatasi **jumlah jenis barang berbeda per nota** — default 1 = tiap nota hanya berisi 1 SKU (barang non-SN yang sama digabung jadi 1 baris dgn qty ≤ cap; SKU SN tetap 1 baris qty=1 + NO_SN unik), disarankan utk toko ban (1 nota = 1-4 ban dari 1 SKU). `penyesuaianAkhir` menambah qty item juga dibatasi cap yg sama. Parameter diatur di Pengaturan → Parameter Generate (dan bisa di-override per generate).
- Ref nota unik global (`refBebas` — baris "dibatalkan" tetap menempati ref).
- Sinkron per request (< 5 detik; ramah shared hosting). Draft tersimpan di DB → review/edit → **Simpan** (potong stok, tandai SN, label rekonstruksi).
- **Undo batch tersimpan**: reversal stok+SN → status `dibatalkan` (baris dipertahankan utk audit); ditolak bila ada nota diedit.
- **Riwayat** (`/generate/riwayat`) urut terbaru di atas (`latest('id')`); batch **draft & dibatalkan** bisa **dihapus** (AJAX, route generate.hapus-batch) — cascade items+transaksi+batch; batch **tersimpan ditolak** (hapus nota via halaman Penjualan, undo via tombol Undo).

### Export / Import Excel
- Format A: kolom dari `template_export_pos_config` (konfigurabel), pre-validasi, **pecah 5.000 baris/file**, sheet PETUNJUK; Format B: laporan + ringkasan; histori + tanda "sudah diupload ke POS". Filter periode export: **rentang tanggal (mulai–selesai) menang atas shortcut bulan**; di UI keduanya saling-eksklusif (input bulan tanpa default, memilih yang satu mengosongkan yang lain).
- Template Data Barang: **prefilled semua SKU existing + dropdown validasi** (SN, STATUS literal; KATEGORI via sheet tersembunyi "Daftar"); import all-or-nothing (`ImportFileException` dgn array errors); lookup KODE_BARANG **case-insensitive** + duplikat dalam file ditolak; STOCK_AWAL hanya utk barang baru.
- Import pembelian dikelompokkan per `REF_PEMBELIAN`, dan **REF_PEMBELIAN dipakai sebagai NOMOR INVOICE** (bukan `PB-...` otomatis; maks 40 karakter — isi sebagai TEKS agar nol di depan tidak hilang). **Re-upload dengan REF yang sama = UPDATE invoice tsb** (upsert via `PembelianService::edit`, batal efek lama → terapkan baru; stok & histori disesuaikan), bukan membuat ganda — hanya utk invoice `sumber=import_excel`; bentrok dgn nomor transaksi non-import ditolak. All-or-nothing tetap: 1 error → seluruh file (termasuk update) di-rollback. Return & pesan flash: "X invoice baru, Y invoice diperbarui". **BAYAR_DP utk CASH boleh kosong = bayar pas** (di-isi otomatis = total per grup; diisi kurang dari total → file ditolak); HUTANG bebas DP. Supplier harus sudah terdaftar. Catatan: format `GEN-...` HANYA dari Generate (rekonstruksi), bukan upload pembelian. **Template Excel pembelian**: kolom SUPPLIER dropdown dari master supplier (sheet hidden `Daftar`), JENIS_TRANSAKSI dropdown CASH/HUTANG, sheet PETUNJUK panduan upload (termasuk aturan REF/upsert), dan **TANPA baris contoh data**.

### Tracking Stok (/stok-tracking — Stock Aging Report)
- Tujuan: lihat stok yang masih tersisa dari batch pembelian per bulan, utk prioritas menghabiskan stok terlama (FIFO) via penjualan manual.
- **Algoritma**: per barang dgn stock > 0, ambil semua kartu_stok masuk (masuk_pembelian, stok_awal, opname positif) + keluar (keluar_penjualan); simulasi FIFO — tiap keluar menghabiskan batch paling tua; sisa per batch = qty asli − yg dikonsumsi. Batch dgn sisa > 0 ditampilkan.
- View: **Ringkasan Total Stok Keseluruhan** (luar filter — query `Barang::sum('stock')`) + **filter bulan** (checkbox; default semua bulan) + tabel per bulan (SKU, kode, sumber Pembelian/Stok Awal/Penyesuaian, tgl masuk, qty awal, sisa, umur hari).
- Catatan: opname bisa positif/negatif; pemfilteran & tampilan tabel berbasis bulan dari batch tersisa.

## 6. Jebakan Teknis yang Diketahui

- **PhpSpreadsheet v5**: validasi memakai `PhpOffice\PhpSpreadsheet\Cell\DataValidation` (+ `setSqref` lalu `setDataValidation`); `setShowDropDown(false)` = panah tampil (konvensi terbalik); `setColumnWidth` tidak ada → pakai `getColumnDimension()->setWidth()`.
- **Reader vs Writer PhpSpreadsheet (jebakan shared hosting)**: `Reader\Xlsx` (dipakai import pembelian/barang/update-harga via `bacaSheet()`) butuh ekstensi **simplexml, dom, xml, xmlreader**; `Writer` (download template & export A/B) **cukup xmlwriter + zip**. Gejala khas di Hostinger: export & download jalan, tapi upload Excel 500/putih karena web PHP-FPM belum mengaktifkan ekstensi XML-reader (CLI SSH bisa beda dgn FPM). Mitigasi: `ExcelImportService::cekEkstensiExcel()` = pre-flight (dipanggil di tiap template/import, lempar `ImportFileException` dgn pesan jelas) + ketiga controller import meng-catch `Throwable` → `Log::error` + flash ramah (tidak pernah 500 putih lagi); kartu "Status Lingkungan Excel" di Pengaturan utk diagnosa cepat. Validasi upload: `mimes:xlsx, max:10240`.
- Cache statis `HargaService::$cacheHarga` bocor antar-test → reset di setUp. **`GenerateService::hargaBarang` memakai cache INSTANCE `$hargaCacheBarang` + reset di awal `generate()`** (JANGAN `static` — id barang bertabrakan antar-test/proses).
- **GenerateService jebakan yang sudah diperbaiki:** `hariBatas = timeline - simSold - soldToday`; habis per hari hanya `soldToday=PHP_INT_MAX`, **jangan** `unitSisa=-1` global (bug hari lompat); `initialBalance` = sum `kartu_stok.qty` sebelum periode wajib dihitung (stok Mei untuk generate Juni); bobot harian = `avail` (stok tersedia di awal hari), bukan `masuk` hari itu; `nota_min/max` kini 1–100 (validasi & view `max=100`) untuk target 1.2B; `penyesuaianAkhir` iterasi 300 + batch top-up.
- Instance model bisa **stale** setelah reversal stok → `->fresh()` sebelum mutasi.
- String PHP dgn backslash utk formula Excel (mis. "$A$1") mudah error escaping; tulis hati-hati.
- **Selector kelas dgn slash** (mis. `.bg-red-50/70`) di CSS tulis-tangan: slash WAJIB diescape (`.\bg-red-50\/70`) atau dihindari — Lightning CSS (Vite) memperingatkan & browser bisa membuang aturan. Kalau utilitas dipakai lewat `@apply` (mis. `bg-red-50/70` di `.row-overdue`), override dark mode targetkan **kelas hasil mount** (`.dark .tbl tbody tr.row-overdue`), bukan nama utilitas.
- Timezone app `Asia/Jakarta`; `date` shell bisa beda dgn `now()` Laravel saat scripting.
- Tambah status baru → pastikan semua query laporan tetap filter `tersimpan`.
- **Urutan FK migration (MySQL vs SQLite)**: FK yang menunjuk ke tabel yang dibuat oleh migration dgn timestamp *belakangan* GAGAL di MySQL/MariaDB (error 1005 "Foreign key constraint is incorrectly formed") tapi lolos diam-diam di SQLite dev (FK tak di-enforce). Contoh historis: histori_harga, referensi_sn, kartu_stok menunjuk ke invoice_pembelian & transaksi_penjualan. **Aturan**: definisikan kolom FK *tanpa constraint* di migration asli (cukup foreignId(...)->nullable()), lalu tambahkan constraint-nya di *migration terpisah* setelah kedua tabel ada (lihat 2026_09_06_000001..000004: Schema::table(...) + $table->foreign(...) + ->nullOnDelete()). SQLite mendukung Schema::table()->foreign()/dropForeign via strategi rebuild tabel, jadi migrate:fresh lokal tetap hijau — tapi wajib diverifikasi di MySQL sebelum deploy.
- **FK `kartu_stok` (2026_10_02_000001)**: `invoice_pembelian_id` & `transaksi_penjualan_id` diubah dari `nullOnDelete` → **`restrictOnDelete`** — invoice/penjualan yang masih punya baris kartu_stok TIDAK bisa dihapus di luar alur aplikasi (mencegah stok yatim); alur hapus aplikasi selalu `batalEfekPembelian/batalEfekPenjualan` dulu (hapus baris kartu), jadi tetap aman.

## 7. Checklist Menambah Fitur

1. Ikuti pola: Services (logika + transaksi) → Controller (validasi + flash) → route bernama → Blade (komponen/partial) → test `tests/Feature`.
2. Jaga aturan "all-or-nothing" & status filter; uji alur ganda (Cash/Hutang, draft/tersimpan/batal).
3. `php artisan test` hijau; smoke via curl/browser; update README & dokumen ini bila aturan bisnis berubah.

## 8. Status Fitur (per Agustus–Sept 2026)

**Selesai:** login+throttle, dashboard (periode omset bulan/tahun/rentang, kartu hutang & stok, transaksi terakhir dipisah, barang terbaru), Data Barang (template prefilled+dropdown, import, update harga, kartu stok/opname, barcode), Supplier, **Tracking Stok** (stock aging FIFO, ringkasan total + filter bulan), Pembelian (Cash/Hutang, pelunasan, import excel, tab nav dalam halaman, **bulk print/cetak terpilih**, **AJAX delete + bulk delete**, **filter periode (bulan/tahun/rentang) + supplier di Hutang/Lunas — admin & publik**), Penjualan (satu laman, edit nota, struk cetak+NPWP+footer PPN, **bulk print**, **AJAX delete + bulk delete**, **kartu omset + sisa target + filter periode sinkron kartu & tabel — default kartu Bulan Berjalan, tabel Semua bila tanpa filter; 1-100 nota/hari**), Generate (draft→review→simpan, undo batch, hapus batch draft/dibatalkan di Riwayat, rekonstruksi bisa dihapus langsung, **distribusi per-hari proporsional avail + stok awal, qty acak, nota 1–100 untuk target besar, penyesuaian agresif**), Export A/B + histori (tombol hapus per item, filter tanggal yang benar), Pengaturan (NPWP, parameter generate 1–100, ubah password admin, fix libur_dates, **terapkan margin global baru ke semua SKU otomatis** — form WAJIB di luar form utama pengaturan.update, HTML tak mengizinkan form bersarang; **kartu Status Lingkungan Excel**), **Dark mode toggle** (gelap/terang), **Mode Publik tanpa login** (read-only di `/publik`, §9 — penjualan & pembelian kini dengan kartu/filter).

**Belum/tunda:** tombol "Cetak/Kembali" di view print sengaja dihapus; penggantian footer lama menjadi "*Harga sudah termasuk PPN." sudah diterapkan di penjualan & pembelian print; untuk mode publik: **PIN akses opsional** bila data dianggap terlalu terbuka & **tautan "Lihat Mode Publik"** dari sidebar admin (belum dikerjakan).

**Bulk print (cetak terpilih):** di halaman Penjualan, Data Hutang Belum Lunas, dan Data Pembelian (Lunas/Cash) ada kolom ceklis per baris + tombol "Cetak Terpilih (N)" di toolbar. Submit POST route print-batch (penjualan.print-batch / pembelian.print-batch) dengan ids[]; server memvalidasi (min 1 id, integer; id tak dikenal dibuang diam-diam; penjualan hanya status=tersimpan; tanpa id valid → flash error) lalu membuka satu halaman cetak berisi semua dokumen terpilih (class .print-page, break-inside: avoid via @media print; tiap nota diberi padding vertikal 24px atas-bawah agar tidak menempel — berlaku di kedua mode page break). Halaman cetak punya toggle **"Halaman baru per nota"** (partial partials.bulk-print-toggle; default aktif, tanpa JS tetap page break per nota karena .pb-off mematikan page-break-after via CSS; kontrol non-print di pojok kanan atas, disembunyikan saat print via @media print). Markup dokumen dipakai bersama via partial penjualan.partials.struk & pembelian.partials.invoice; tab baru via target="_blank" (gestur klik → lolos popup-blocker), cetak dengan Ctrl+P.

**Keputusan penting:** navigasi Pembelian via tab bar di dalam halaman (bukan submenu sidebar); item "Penjualan Manual" diganti "Penjualan" (satu laman); **hapus nota/barang/batch via AJAX** (tanpa redirect — row langsung hilang, fallback redirect utk non-JS); **dark mode**: toggle di sidebar (body/.dark pada <html>, localStorage 'theme', default prefers-color-scheme, CSS overrides variabel @theme, cetak selalu terang); edit nota rekonstruksi mengunci undo batch tapi nota tsb boleh dihapus langsung (guard rekonstruksi dihapus); **sidebar**: desktop minimize (rail ikon 64px, localStorage via body.sidebar-minimized), mobile drawer tanpa backdrop-blur (sidebar z-40 di atas overlay z-30).

## 9. Mode Publik (tanpa login, read-only)

**Tujuan:** versi tampilan tanpa autentikasi — seluruh menu read-only (mis. layar display/kiosk atau akses tamu). User hanya melihat data, tidak ada aksi mutasi apa pun.

- **Route:** grup `Route::prefix('publik')->name('publik.')->group(...)` di `routes/web.php`, **tanpa middleware auth** (hanya grup `web`). Nama route = `publik.` + nama admin yg sama persis (mis. `publik.barang.kartuStok`, `publik.pembelian.invoice-hutang`) supaya pola closure `$r` generik.
- **Cakupan:** Dashboard (/publik), Data Barang (index/show/kartu-stok/barcode), Tracking Stok, Pembelian (hutang/lunas/show/print — **daftar hutang/lunas kini dengan filter periode & supplier**), Penjualan (index/print). **Tidak ada route** untuk Supplier, Generate, Export, Pengaturan → akses langsung `/publik/supplier` dst. = **404** (bukan login, bukan halaman kosong).
- **Controller:** `app/Http/Controllers/Publik/*` — read-only; menyalin query controller admin dan me-render **view admin yang sama** dengan variabel tambahan `'publik' => true`. Tanpa method create/store/update/destroy/import/bayar/bulk.
- **Pola view (guard `$publik`):** tiap `@section('content')` yang terdampak dimulai dengan
  `@php($publik = $publik ?? false)` dan `@php($r = fn (string $n, ...$p) => route(($publik ? 'publik.' : '') . $n, ...$p))`.
  - Tombol/link mutasi admin (tambah/edit/hapus/import/template/opname/stok-awal/bayar/bulk print/bulk delete/CTA dashboard) dibungkus `@if(! $publik) ... @endif` (tetap `route()` polos).
  - Link lintas-mode (index/show/print/kartuStok/barcode/tab pembelian/filter stok-tracking) memakai `$r(...)` → di admin = `route('...')`, di publik = `route('publik....')`.
  - Layout `layouts/app.blade.php`: sidebar hanya 5 menu + link **Login** (bukan Logout), chip header "Mode Publik", subjudul brand "Mode Publik — Lihat Saja", `<meta name="robots" content="noindex, nofollow">`.
- **Aturan konten publik:** label sumber (Manual/Rekonstruksi) & badge "diedit" **disembunyikan** (dashboard + penjualan); Penjualan publik = **daftar transaksi + kartu omset & filter periode** (kartu Total Omset — `labelPeriode` + `omsetPeriode` + `target/sisa` bila ada batch tersimpan, sama persis seperti admin; filter `bulan/tahun/from-to` via `periodeDariRequest`, tanpa tab sumber) tanpa kolom Sumber, tanpa edit — detail expand & cetak struk tetap ada; **Pembelian publik = daftar hutang/lunas + filter periode & supplier** (dropdown supplier, `bulan/tahun/from-to`, `q` & `entries`), tanpa bulk/bayar/edit — cetak & detail tetap ada; dashboard tanpa kartu "Aksi Cepat", tanpa CTA "Bayar Sekarang/Order Sekarang", tanpa baris "Sumber Transaksi"; pencarian/show-entries/paginasi tetap aktif.
- **Jebakan yang sudah dikenal:**
  - `auth()->user()` bisa null saat guest → layout memakai null-safe `auth()->user()?->name` dan cabang `@if(! $publik)`.
  - Menambahkan route publik baru → nama Wajib mengikuti nama admin (`publik.` + nama yg sama) agar `$r` bekerja.
  - Di test, `assertSee('title="..."')` butuh argumen kedua `false` (escaping HTML default).
  - Kolom ceklis & kolom Sumber disembunyikan → `colspan` baris detail/empty dibuat dinamis (`{{ $publik ? 5 : 7 }}`).
- **Tidak menyentuh CSS/JS** (murni Blade+PHP) → tanpa `npm run build`; serangan ke route mutasi tetap tertahan middleware auth (guest di-redirect ke /login).
- **Ide tambahan (belum dikerjakan):** PIN akses opsional utk mode publik; tautan "Lihat Mode Publik" dari sidebar admin; halaman landing publik terpisah.

