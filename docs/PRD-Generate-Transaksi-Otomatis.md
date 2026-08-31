# PRD: Fitur Generate Transaksi Otomatis (Rekonstruksi Penjualan Berbasis Omset & Stok)

**Produk:** Aplikasi Kasir/POS Bengkel & Toko
**Fitur:** Auto-Generate Transaksi Penjualan dari Target Omset Bulanan
**Status:** Draft v1
**Dibuat:** 31 Agustus 2026

---

## 1. Latar Belakang & Masalah

Pemilik toko/bengkel sering kali **tahu total omset harian/bulanan** (misalnya dari uang kas yang masuk) tetapi **tidak sempat mencatat setiap transaksi satu per satu** saat terjadi. Akibatnya:

- Laporan penjualan per-item tidak ada, padahal stok barang **rutin dicatat setiap hari saat masuk**.
- Sulit merekonsiliasi omset dengan pengurangan stok — tidak tahu barang apa saja yang sebenarnya laku.
- Pembukuan jadi tidak konsisten antara "uang yang masuk" dan "barang yang keluar dari gudang".

**Tujuan fitur ini:** memungkinkan pemilik mengisi *total omset* suatu periode (misal Juni = Rp1.000.000), lalu sistem **merekonstruksi** kumpulan transaksi harian yang masuk akal — dengan syarat **tidak pernah menjual barang melebihi stok yang tersedia pada tanggal tersebut**.

> Catatan penting: ini adalah *rekonstruksi estimasi* dari data yang sudah benar-benar terjadi (uang kas & stok riil), bukan alat untuk membuat angka fiktif. Hasilnya sebaiknya ditandai sebagai "data estimasi/rekonstruksi" di sistem, terutama bila akan dipakai juga untuk keperluan pelaporan pajak/keuangan formal — supaya tetap bisa dibedakan dari transaksi yang dicatat real-time oleh kasir.

---

## 1.1 Platform & Deployment Target

- **Bentuk aplikasi:** Web app, diakses via browser (desktop & mobile-responsive).
- **Hosting:** Shared hosting Hostinger (bukan VPS/cloud dedicated) → ini membatasi pilihan teknis:
  - Tidak ada akses root, resource CPU/RAM terbatas, proses long-running/background worker terbatas (umumnya hanya cron job berjadwal, bukan queue worker terus-menerus).
  - **Stack wajib: PHP (Laravel) + MySQL.** Node.js tidak dipakai — shared hosting Hostinger mendukung PHP native dengan baik dan paling kompatibel di semua paket, sementara Node.js butuh paket khusus (Business/Cloud ke atas) yang tidak bisa diasumsikan tersedia. Laravel dipilih karena ekosistemnya matang untuk kebutuhan seperti antrian batch, validasi form, dan export Excel (lewat PhpSpreadsheet, lihat bagian 7).
  - Proses "Generate Transaksi" (algoritma di bagian 6) harus dirancang **sinkron/batch per-request** dengan batas waktu eksekusi wajar (misal < 30 detik, sesuai limit `max_execution_time` shared hosting), bukan job queue yang jalan di background terus-menerus.
- **Keamanan (wajib, karena shared hosting rawan jadi target serangan umum):**
  - HTTPS wajib (SSL gratis dari Hostinger/Let's Encrypt).
  - Hash password dengan bcrypt/argon2, tidak simpan plaintext.
  - Proteksi terhadap SQL Injection (gunakan prepared statement/ORM), XSS, dan CSRF token di setiap form.
  - Backup database berkala (Hostinger cron + export terjadwal ke storage terpisah).
  - Rate limiting / captcha di halaman login untuk mencegah brute force.
  - File `.env`/config kredensial database tidak boleh berada di folder yang bisa diakses publik.

---

## 1.2 Autentikasi & Dashboard (Home)

Belum ada halaman pertama yang dijelaskan sebelumnya — bagian ini melengkapinya.

**a. Login**
- Form: Username/Email + Password, tombol "Login".
- Karena hanya **1 akun admin** (lihat 9), **tidak ada halaman registrasi publik** — akun admin dibuat sekali lewat seeder/`artisan` command saat instalasi awal, atau lewat halaman "Setup Awal" yang hanya bisa diakses sekali (terkunci otomatis setelah akun pertama dibuat).
- Sesi login: timeout otomatis setelah periode tidak aktif tertentu (mis. 2 jam, dikonfigurasi), demi keamanan (konsisten dengan 1.1).
- **Lupa password**: karena hanya 1 admin dan app di shared hosting (belum tentu ada layanan email transaksional terpasang), v1 cukup sediakan mekanisme reset manual lewat akses database/artisan command oleh developer — bukan alur "reset via email" otomatis, kecuali SMTP sudah dikonfigurasi (opsional, fase 2).

**b. Dashboard (Home)**
Ringkasan cepat begitu login, isinya kartu-kartu info:
- Total omset bulan berjalan (dari transaksi manual + hasil generate yang sudah disimpan).
- Jumlah invoice **Hutang Belum Lunas** (5.2a) beserta highlight berapa yang **jatuh tempo dalam 7 hari ke depan atau sudah lewat**.
- Jumlah SKU dengan **stok menipis/habis** (ambang batas bisa dikonfigurasi di Pengaturan Toko, lihat 4.5).
- Shortcut ke menu yang paling sering dipakai: Transaksi Pembelian, Data Barang, Generate Transaksi.
- Menu sidebar utama: Data Barang (4.3), Data Supplier (4.4), Pembelian (5), Generate Transaksi (6/7), Export (8), Pengaturan (4.5), Logout.

---

## 2. Tujuan (Goals)

1. User bisa input target omset per periode (harian/mingguan/bulanan).
2. Sistem otomatis membuat daftar transaksi (tanggal, item, qty, harga, total) yang:
   - Total keseluruhannya mendekati/mencapai target omset.
   - Distribusi ke tiap hari **proporsional terhadap stok yang masuk** pada rentang tanggal tersebut.
   - Tidak pernah membuat stok minus (tidak jual barang yang belum ada / sudah habis).
3. User bisa review & edit hasil generate sebelum disimpan permanen (bukan langsung final).
4. Data stok tetap konsisten — setelah generate, kartu stok otomatis berkurang sesuai transaksi yang dibuat.

### Non-Goals (di luar cakupan v1)
- Prediksi/forecasting omset masa depan yang belum terjadi.
- Multi-cabang/multi-gudang (asumsi v1: 1 lokasi toko).
- Integrasi otomatis ke e-Faktur/pelaporan pajak.
- **Komponen "jasa service" (non-stok)** belum masuk cakupan v1 — generate transaksi hanya untuk penjualan barang bersstok. Namun struktur data (lihat 4) sebaiknya dirancang agar tidak menyulitkan penambahan jenis "item jasa" (tanpa stok, tanpa KODE_BARANG fisik) di versi berikutnya — misal dengan menyediakan flag `tipe_item: barang | jasa` di master data sejak awal, meski untuk saat ini hanya `barang` yang dipakai.

---

## 3. User Story

- Sebagai pemilik toko, saya ingin memasukkan total omset bulan Juni, agar sistem membuatkan draft transaksi harian yang sesuai dengan stok yang saya input, sehingga saya tidak perlu input satu-satu.
- Sebagai pemilik toko, saya ingin melihat & mengedit draft transaksi sebelum disimpan, agar hasilnya tetap masuk akal dan bisa saya sesuaikan dengan ingatan saya.
- Sebagai pemilik toko, saya ingin sistem menolak generate transaksi yang membuat stok minus, agar data stok saya tetap valid.

---

## 4. Data yang Dibutuhkan (Prasyarat)

Fitur ini **bergantung penuh** pada data berikut sudah ada di sistem:

| Data | Keterangan |
|---|---|
| Kartu Stok Masuk | Tanggal masuk, nama item, **KODE_BARANG**, qty, harga beli, harga jual, status SN/non-SN |
| Kartu Stok Keluar (jika ada) | Transaksi yang *sudah* dicatat manual sebelumnya (agar tidak dobel) |
| Kategori/margin harga | Untuk menentukan harga jual wajar per item |
| Kalender periode | Rentang tanggal target omset (mis. 1–30 Juni) |
| **Referensi Barang (Master POS)** | Daftar `KODE_BARANG` **harus identik** dengan master barang di aplikasi POS Bengkel tujuan (lihat 4.2), agar hasil export bisa langsung diimport balik |
| **Referensi Customer** | Tidak diperlukan — semua transaksi hasil generate memakai `CUSTOMER_ID = 0` (Customer Umum), sesuai keputusan produk (lihat 4.2) |
| **Referensi No. SN (untuk barang ber-SN)** | Daftar nomor serial yang tersedia per SKU ber-SN, sinkron dengan `REFERENSI_SN` di POS Bengkel |

Jika data stok masuk tidak lengkap/tidak tercatat harian, akurasi hasil generate akan rendah — ini harus disampaikan ke user sebagai warning di UI.

---

## 4.1 Logika Harga Beli, Margin & Harga Jual

Saat input stok masuk, user hanya mengisi **harga beli**. Harga jual **dihitung otomatis** dari harga beli + margin, dengan dua level pengaturan margin:

### a. Margin Global (Default untuk Semua SKU)
- User set 1 angka persentase margin default di **Pengaturan Toko** (misal: 20%).
- Berlaku otomatis untuk semua SKU baru yang tidak punya margin khusus.
- Formula: `harga_jual = harga_beli + (harga_beli × margin_global%)`

### b. Margin per SKU/Item (Override, Tidak Tetap)
- Untuk item tertentu, user bisa set margin **berbeda dari default global** (misal: sparepart fast-moving margin 15%, item langka margin 40%).
- Item yang punya override ini akan **selalu pakai margin sendiri**, walau margin global diubah nanti.
- Field per SKU: `margin_override` (nullable) — jika `null`, ikut margin global; jika diisi, pakai nilai ini.

### c. Aturan Tambahan
- Setiap SKU punya **toggle** di halaman detail item: **"Hitung Ulang Otomatis"** vs **"Kunci Harga Jual Manual"**.
  - **Mode Otomatis (default saat SKU baru dibuat):** setiap kali harga beli berubah (mis. beli batch baru dengan harga lebih mahal/murah), harga jual otomatis dihitung ulang mengikuti margin yang berlaku (global atau override).
  - **Mode Kunci Manual:** harga jual tetap seperti yang diset user, walau harga beli/margin berubah — biasanya dipakai kalau sudah ada price list cetak yang tidak mau berubah-ubah tiap kali restock.
  - User bisa toggle kapan saja; saat pindah dari "Kunci Manual" → "Otomatis", sistem langsung hitung ulang harga jual saat itu juga berdasarkan harga beli & margin terkini.
- Histori perubahan harga beli & margin per SKU sebaiknya disimpan (log), agar transaksi lama tetap merujuk harga yang berlaku saat itu, bukan harga terbaru — ini penting juga untuk fitur "Generate Transaksi" di bagian 6, supaya harga jual yang dipakai di transaksi rekonstruksi tanggal 5 Juni memakai harga yang berlaku tanggal 5 Juni, bukan harga hari ini.

### d. Field Tambahan di Master Data
| Field | Level | Keterangan |
|---|---|---|
| `margin_default` | Pengaturan Toko | Persentase margin global |
| `margin_override` | Per SKU | Opsional, override margin global |
| `mode_harga_jual` | Per SKU | Enum: `otomatis` \| `manual_terkunci` — dikontrol lewat toggle di UI |
| `histori_harga` | Per SKU (log) | Rekam harga beli, margin, dan harga jual efektif per tanggal |

---

## 4.2 Kompatibilitas dengan Aplikasi POS Bengkel Existing

Aplikasi ini **bukan pengganti** POS Bengkel yang sudah ada (fitur "Super Kasir" untuk import transaksi massal via Excel), melainkan **alat bantu** yang menyiapkan data transaksi, lalu diekspor dalam format yang **langsung bisa diupload** ke fitur import POS Bengkel tersebut. Karena itu, sejumlah field & aturan di aplikasi ini **wajib mengikuti** ketentuan template import POS Bengkel:

- Setiap SKU di master barang aplikasi ini wajib punya field `KODE_BARANG` yang **sama persis** dengan kode di REFERENSI_BARANG POS Bengkel (case-sensitive, tidak boleh beda spasi/format).
- Setiap SKU wajib ditandai apakah **ber-SN** atau **non-SN** — ini menentukan cara algoritma generate memilih unit yang dijual (lihat 6) dan wajib QTY 1 per baris kalau ber-SN.
- Semua transaksi hasil generate **selalu memakai `CUSTOMER_ID = 0` (Customer Umum)** — sudah menjadi keputusan produk, tidak perlu mapping ke customer spesifik. Field referensi customer tidak diperlukan di v1.
- Format tanggal transaksi mengikuti `YYYY-MM-DD HH:MM` (sesuai contoh: `2026-08-06 13:30`) — jam bisa digenerate acak dalam jam operasional toko (misal 08:00–17:00) agar terlihat wajar.

### Alur Integrasi: Manual (Tanpa API)

POS Bengkel yang dipakai **tidak menyediakan API import** — satu-satunya jalur masuk data adalah upload file Excel manual lewat menu Super Kasir. Konsekuensinya untuk desain aplikasi ini:

- Alur kerjanya tetap **dua langkah terpisah**: (1) generate & export file dari aplikasi ini → (2) user mengunduh file lalu mengunggahnya sendiri ke menu Super Kasir POS Bengkel. Tidak ada integrasi otomatis/real-time antar sistem.
- Karena tidak ada API untuk verifikasi balik (aplikasi ini tidak bisa tahu apakah file yang diexport benar-benar sukses diimport ke POS), **pre-validasi sebelum download** (lihat 8b) jadi lapisan pertahanan utama untuk meminimalkan kegagalan saat file diupload ke POS Bengkel.
- Sebaiknya disediakan tempat bagi user untuk menandai status upload secara manual di aplikasi ini (misal centang "Sudah diupload ke POS" per batch export), supaya ada jejak/histori mana yang sudah benar-benar masuk ke POS dan mana yang masih draft/belum diupload.
- Jika di masa depan POS Bengkel menambahkan API import, alur ini bisa diupgrade tanpa mengubah struktur data inti — cukup menambah adapter pengiriman baru di luar mekanisme export file yang sudah ada.

### Mapping Kolom Bersifat Konfigurabel (Bukan Hardcode)

Karena template Super Kasir berpotensi berubah versi di masa depan (kolom baru, urutan berubah, aturan validasi bertambah), **mapping kolom pada Format A (8b) wajib dibuat konfigurabel**, bukan ditulis tetap di kode program:

- Disediakan halaman **Pengaturan → Template Export POS**, tempat admin bisa mengatur: nama & urutan kolom, kolom mana yang wajib/opsional, format tanggal, serta nilai default per kolom (mis. default `CUSTOMER_ID = 0`, default `JENIS_TRANSAKSI = CASH`).
- Konfigurasi ini disimpan sebagai data (JSON/tabel mapping di database), sehingga saat template POS Bengkel berubah versi, admin cukup menyesuaikan pengaturan tanpa perlu deploy ulang kode.
- Simpan juga **versi template** yang sedang aktif (mis. `template_version: v1`), agar histori export lama tetap tercatat dipakai versi mapping yang mana — berguna untuk audit kalau suatu saat ada perbedaan hasil export antar periode.

---

## 4.3 Modul Master Data Barang

Bagian 4 di atas menyebut "Referensi Barang (Master POS)" sebagai prasyarat, dan bagian 4.1 menjelaskan logika harga/marginnya — tapi belum ada modul konkret tempat data ini **dikelola**. Bagian ini melengkapinya: menu **"Data Barang"**, yaitu **satu-satunya sumber master item** di aplikasi. Baris `KODE_BARANG` di sinilah yang ditarik oleh field **"Barcode / Kode Barang"** saat Transaksi Pembelian (5.1) dan yang direferensikan `daftar_stok_masuk`/`KODE_BARANG` di algoritma Generate Transaksi (bagian 6) serta export ke POS Bengkel (4.2/8b) — jadi field-field di sini menjadi acuan tunggal untuk seluruh alur pembelian & penjualan di aplikasi.

### a. Halaman Daftar ("Data Barang")

- Tombol di atas: **"+ Tambah Data"** (buka form tambah barang baru), **"Download Template Excel"** dan **"Upload Template Excel"** (import massal item baru — kolom template beda dari template pembelian di 5.3, lihat detail di bagian c di bawah), **"Update Harga Excel"** (upload khusus untuk update harga jual banyak item sekaligus tanpa perlu re-import seluruh data barang — kolom minimal: `KODE_BARANG` + `HARGA_JUAL`, hanya berlaku untuk SKU bermode `manual_terkunci` sesuai 4.1c; SKU bermode `otomatis` akan menolak/mengabaikan baris ini karena harganya memang dihitung dari margin, bukan diinput manual).
- Tabel (komponen sama seperti di 5.2 — Show entries, Search, sortable, paginasi), kolom: **No, Kode Barang, Nama, Kategori, Harga Jual, Stock, Status, Aksi**.
- **Status**: badge, nilai contoh `Dijual & Untuk Sevis`. Diusulkan enum: `Dijual` (hanya dijual retail), `Untuk Servis` (khusus dipakai internal saat pengerjaan servis, tidak tampil di pencarian penjualan retail), `Dijual & Untuk Servis` (keduanya). **Beda dengan "komponen jasa service (non-goals di bagian 2)"** — status ini untuk barang berstok yang *juga* dipakai sebagai bahan/sparepart servis, bukan item jasa tanpa stok.
- Aksi per baris — **5 ikon**: **Lihat Detail** (mata, hijau), **Edit** (pensil, biru), **Riwayat/Kartu Stok** (ikon kuning — membuka riwayat mutasi stok masuk-keluar per item ini, yaitu tampilan dari Kartu Stok Masuk/Keluar yang dirujuk di bagian 4, supaya user bisa audit kapan & dari invoice pembelian/penjualan mana stok item ini berubah; halaman riwayat ini juga jadi **satu-satunya tempat** untuk input **Stok Opname**, lewat tombol terpisah — lihat pembatasan field Stock di bagian b), **Cetak Barcode** (ikon teal, cetak label barcode fisik untuk ditempel ke barang — dipakai bersama fitur scan "Barcode / Kode Barang" di 5.1), **Hapus** (tempat sampah, merah — hanya boleh dihapus kalau belum pernah dipakai di transaksi manapun, untuk menjaga integritas histori).

### b. Form Tambah/Edit Data Barang

| Field | Perilaku | Keterangan |
|---|---|---|
| Barcode / Kode Barang | **Read-only saat edit** (bisa diisi bebas saat tambah baru) | Sekali dibuat, tidak boleh diubah — karena jadi kunci referensi ke transaksi pembelian, penjualan, dan export POS Bengkel (4.2). Mengubahnya akan memutus keterkaitan histori |
| Nama Barang | Bisa diedit kapan saja | |
| Deskripsi | Bisa diedit kapan saja | Teks bebas |
| Kategori | Dropdown, bisa diedit | Untuk v1 hanya label pengelompokan/filter, **belum** menentukan margin (margin tetap 2 level: global & per-SKU override, sesuai 4.1) |
| Satuan | Dropdown (mis. `pcs`) | Label unit tampilan; konversi antar satuan (mis. dus↔pcs) di luar cakupan v1 |
| SN or Non-SN | Dropdown, sebaiknya **dikunci setelah ada transaksi pertama** | Menentukan alur wajib SN saat pembelian/penjualan (4.2, 6) — mengubahnya di tengah jalan berisiko bikin data SN yang sudah ada tidak konsisten |
| Harga Beli | **Read-only** di form ini (field abu-abu, tidak bisa diketik) | **Tidak diisi manual di sini** — otomatis terisi dari harga beli transaksi pembelian terakhir (5.1/5.4), dan historinya tersimpan di `histori_harga` (4.1d). Saat tambah barang baru, field ini kosong/0 sampai ada transaksi pembelian pertama |
| Harga Jual | Bisa diedit **hanya jika mode SKU = `manual_terkunci`** (4.1c) | Kalau mode `otomatis`, field ini idealnya tampil read-only juga (dihitung dari Harga Beli + margin) — form referensi belum membedakan kedua mode secara visual, jadi ini perlu ditambahkan: tampilkan toggle "Hitung Ulang Otomatis / Kunci Manual" (4.1c) di form ini, dan field Harga Jual otomatis jadi read-only saat mode `otomatis` dipilih |
| Stock | **Dibatasi**: hanya bisa diedit lewat 2 jalur khusus, bukan diketik bebas kapan saja | (1) **Stok Awal** — saat barang baru pertama kali dibuat dan sudah punya stok fisik sebelumnya (mis. migrasi data lama); (2) **Stok Opname** — koreksi selisih stok sistem vs stok fisik hasil hitung ulang gudang, dilakukan lewat form/tombol terpisah (bukan field angka polos di form Edit ini) yang **mewajibkan alasan** (mis. "opname bulanan", "barang rusak/hilang") dan **selisihnya otomatis tercatat sebagai 1 baris "Penyesuaian/Opname" di Kartu Stok**, lengkap dengan tanggal & user yang melakukan. Di luar 2 jalur ini, stok **tidak boleh berubah manual** — perubahan normal murni dari transaksi Pembelian (+, 5.4) dan Penjualan (−, 6) |
| Lokasi Rak | Bisa diedit manual | Teks bebas, deskriptif — mempermudah staf gudang mencari barang secara fisik, tidak memengaruhi logika stok/harga |
| Status | Dropdown, bisa diedit kapan saja | Lihat enum di bagian (a) |

### c. Import Massal via Excel (Download/Upload Template Excel)

Beda dari template import di 5.3 (yang isinya *transaksi* pembelian), template di sini isinya **data master item itu sendiri** — dipakai untuk onboarding awal (misal migrasi ratusan SKU dari sistem lama) atau menambah banyak SKU baru sekaligus:

| Kolom | Keterangan |
|---|---|
| `KODE_BARANG` | Wajib unik; kalau kode sudah ada di sistem, baris dianggap **update** data barang tsb (bukan duplikat), kecuali field yang read-only (Harga Beli tidak berubah lewat jalur ini) |
| `NAMA_BARANG` | Wajib |
| `DESKRIPSI` | Opsional |
| `KATEGORI` | Wajib, harus cocok dengan daftar kategori yang sudah ada (atau kategori baru otomatis dibuat) |
| `SATUAN` | Opsional, default `pcs` |
| `SN_ATAU_NON_SN` | Wajib, `SN` atau `NON_SN` |
| `HARGA_JUAL` | Opsional — hanya berlaku kalau mode `manual_terkunci`; kalau kosong dan SKU baru, pakai hasil hitung margin default (4.1a) |
| `STOCK_AWAL` | Opsional, default 0 — hanya untuk barang baru/migrasi, tercatat sebagai baris "Stok Awal" di Kartu Stok |
| `LOKASI_RAK` | Opsional |
| `STATUS` | Opsional, default `Dijual & Untuk Servis` |

**Aturan validasi**: sama polanya dengan template lain di dokumen ini (5.3, 8b) — kalau 1 baris gagal validasi (mis. `KODE_BARANG` duplikat dengan aturan yang bertentangan, atau `KATEGORI`/`SN_ATAU_NON_SN` tidak valid), seluruh file dibatalkan, tidak ada data yang berubah.

---

## 4.4 Modul Master Data Supplier

Di 5.1, form Transaksi Pembelian sudah menyebut dropdown **"-- Pilih Supplier --"** dan link cepat **"Tambah Supplier +"**, tapi halaman kelola supplier itu sendiri belum dijelaskan. Bagian ini melengkapinya dengan menu **"Data Supplier"**.

**a. Halaman Daftar ("Data Supplier")**
- Tombol: **"+ Tambah Data"**.
- Tabel (komponen sama seperti 4.3/5.2): kolom **No, Nama Supplier, Kontak, Alamat, Total Hutang Berjalan, Aksi**.
  - **Total Hutang Berjalan**: agregat otomatis dari `Sisa Hutang` semua invoice pembelian dari supplier ini yang masih "Belum Lunas" (5.2a) — memudahkan user melihat supplier mana yang piutangnya ke mereka masih besar.
- Aksi per baris — **3 ikon**: **Lihat Riwayat Pembelian** (daftar invoice pembelian dari supplier ini, filter dari 5.2), **Edit**, **Hapus** (hanya boleh dihapus kalau belum pernah ada transaksi pembelian dari supplier ini, sama polanya dengan aturan hapus di 4.3a).

**b. Form Tambah/Edit Supplier**
| Field | Keterangan |
|---|---|
| Nama Supplier | Wajib |
| No. Telepon/Kontak | Wajib — dipakai juga untuk konfirmasi saat jatuh tempo hutang mendekat |
| Email | Opsional |
| Alamat | Opsional |
| Catatan | Opsional, teks bebas (mis. termin pembayaran khusus, kontak person) |

**c. Mode Tambah Cepat vs Lengkap**
- Link **"Tambah Supplier +"** di form Transaksi Pembelian (5.1) membuka **modal ringkas** (hanya Nama Supplier + No. Telepon, field lain opsional/menyusul) supaya tidak memutus alur input pembelian yang sedang berjalan.
- Menu **"Data Supplier"** di sidebar membuka **form lengkap** (semua field di atas), untuk pengelolaan detail.

---

## 4.5 Modul Pengaturan Toko

Beberapa pengaturan sudah disebut tersebar di berbagai bagian dokumen ini (margin default di 4.1a, mapping kolom export POS di 4.2, hari libur & jam operasional di 7, dst) — bagian ini **mengonsolidasikannya** jadi 1 halaman **"Pengaturan"**, supaya developer tidak melewatkan salah satu saat implementasi. Halaman ini hanya bisa diakses & diubah oleh 1 akun admin (9).

| Sub-bagian Pengaturan | Field | Dirujuk di |
|---|---|---|
| **Info Toko** | Nama Toko/Bengkel, Alamat, No. Telepon, Logo (untuk header cetak invoice/barcode, lihat 5.2 & 4.3a) | Baru (dipakai di semua fitur cetak) |
| **Harga & Margin** | `margin_default` (persentase) | 4.1a |
| **Jam & Hari Operasional** | Jam buka–tutup (default 08:00–17:00), hari libur/tanggal tutup (pilih manual per tanggal atau set hari tertentu tiap minggu, mis. semua hari Minggu) | 7 |
| **Parameter Generate Transaksi** | Jumlah nota/hari (default 1–8), toleransi % (default ±2–5%), rentang variasi harga jual (default −10% s.d. 0%), item yang dikecualikan dari generate | 6, 7 |
| **Ambang Stok Menipis** | Angka qty minimum per kategori/global, dipakai untuk notifikasi di Dashboard (1.2b) | Baru |
| **Template Export POS** | Nama & urutan kolom, kolom wajib/opsional, format tanggal, nilai default per kolom, versi template aktif (`template_version`) | 4.2 |

> Semua field di atas disimpan sebagai 1 baris konfigurasi per toko (tabel `toko_settings`, lihat skema di bagian 12), bukan hardcode di kode — konsisten dengan prinsip "konfigurabel bukan hardcode" yang sudah dipakai di 4.2.

---

## 5. Modul Pengadaan Stok (Pembelian)


Sejauh ini PRD berfokus pada *keluarnya* stok (penjualan/generate transaksi). Bagian ini melengkapi alur **masuknya** stok — yaitu proses pembelian barang dari supplier, yang menjadi sumber data "Kartu Stok Masuk" yang dirujuk di bagian 4. **Ini jawaban langsung atas pertanyaan "dari mana munculnya stok": stok tidak diinput manual terpisah, melainkan otomatis bertambah begitu 1 transaksi pembelian di modul ini disimpan** (detail mekanismenya di 5.4).

Desain modul ini **mereplikasi 1:1 alur menu Pembelian di aplikasi POS Bengkel** yang sudah kamu pakai (lihat referensi tangkapan layar), supaya user tidak perlu belajar pola baru dan supaya nanti kalau butuh cross-check data antara aplikasi ini dengan POS Bengkel, tampilannya sudah familiar. Menu **"Pembelian"** di sidebar punya 2 sub-menu: **(5.1) Transaksi Pembelian** (form input pembelian baru, tab Cash/Hutang) dan **(5.2) Invoice Pembelian** (daftar riwayat, terpisah tampilan Hutang Belum Lunas vs Sudah Lunas, + mekanisme pelunasan).

### 5.1 Sub-menu: Transaksi Pembelian

Form input pembelian, dengan **2 tombol toggle di kiri atas: Cash dan Hutang** — toggle ini mengubah bagian "ringkasan biaya" di kanan bawah (lihat di bawah), sisanya (tabel item, supplier, tanggal) sama untuk kedua mode.

**Baris tombol paling atas (di atas No. Invoice), berlaku untuk kedua tab:**
- **Download Template Excel** (tombol hijau) dan **Upload Transaksi Excel** (tombol kuning) — jalur import massal, lihat detail kolom & aturan di 5.3.

**Field umum (berlaku di kedua tab):**
- **No. Invoice**: default `0`/auto-generate oleh sistem, tapi tetap bisa diedit manual lewat ikon pensil di sebelahnya kalau user ingin menyamakan dengan nomor invoice fisik dari supplier.
- **Pencarian barang**: input **Barcode / Kode Barang** + tombol cari (kaca pembesar) di kanan atas, mencari `KODE_BARANG` dari master **Data Barang (4.3)** — begitu ditemukan, baris baru otomatis ditambahkan ke tabel pembelian di bawahnya dengan Nama & (kalau ada) Harga Beli terakhir sebagai default yang bisa diedit.
- **Tabel item pembelian**: kolom **No, Nama, Harga Beli, QTY, Diskon Item, Sub Total, Aksi** (hapus baris).
- **Diskon Item**: nominal Rp, opsional per baris (default 0) — **ini keputusan tetap, bukan sekadar mengikuti referensi lama**, karena mengikuti struktur **e-Faktur** yang mencatat diskon per baris item, baru diskon tambahan di level keseluruhan invoice ("Diskon/Potongan" di bagian ringkasan). Jadi ada 2 level diskon yang keduanya wajib tersedia:
  - **Diskon Item** (per baris): `Sub Total baris = (Harga Beli × QTY) − Diskon Item`.
  - **Diskon/Potongan** (level invoice, di bagian ringkasan bawah): dikurangkan dari total setelah semua Sub Total per baris dijumlahkan — lihat "Ringkasan biaya" di bawah.
- **Supplier**: dropdown "-- Pilih Supplier --", dengan link cepat **"Tambah Supplier +"** di bawahnya kalau supplier belum ada di daftar.
- **Tanggal dan Waktu Transaksi Pembelian**: date-time picker, default waktu saat ini tapi bisa diubah mundur (untuk input pembelian yang telat dicatat).

**Ringkasan biaya (di bawah tabel, sisi kanan — sama untuk kedua tab):**
- **Subtotal**: `Rp.` — jumlah semua Sub Total per baris.
- **Diskon / Potongan**: `Rp.` input manual, dengan keterangan kecil **"Nominal rupiah, bukan persen."** — potongan di level keseluruhan invoice (misal supplier kasih potongan tambahan karena beli banyak sekaligus).
- **Total Setelah Diskon**: `Subtotal − Diskon/Potongan`, tampil read-only.

**Khusus Tab Cash (tombol "Cash" berwarna biru aktif):**
- **Bayar**: `Rp.` input manual, label berwarna merah.
- **Kembali**: `Bayar − Total Setelah Diskon`, read-only, otomatis dihitung, tidak boleh minus.
- **Validasi wajib**: tombol **"Simpan Payment"** di tab Cash **diblokir (disabled + pesan error)** selama `Bayar < Total Setelah Diskon` — karena tab Cash mengasumsikan lunas penuh saat itu juga, tidak ada konsep pembayaran sebagian. Kalau user memang mau bayar sebagian, harus pindah ke tab **Hutang** (yang punya konsep DP & Sisa Hutang).
- Tombol **"Simpan Payment"** (ikon keranjang) → menyimpan transaksi sebagai **lunas**, otomatis menambah qty ke Kartu Stok Masuk per item, dan menghitung ulang harga jual sesuai margin (lihat 4.1) untuk tiap SKU yang dibeli.

**Khusus Tab Hutang (tombol "Hutang" berwarna biru aktif):**
- **Jatuh Tempo**: date picker terpisah di bawah "Tanggal dan Waktu Transaksi Pembelian", dengan keterangan kecil **"Otomatis diisi tanggal terakhir pada bulan ketiga setelah bulan transaksi."** kalau dikosongkan (pola sama dengan aturan JATUH_TEMPO di template Super Kasir, section 4.2 — konsisten di seluruh aplikasi).
- **DP**: `Rp.` input manual, label berwarna merah — nominal uang muka yang sudah dibayar saat transaksi (boleh 0/kosong).
- **Sisa Hutang**: `Total Setelah Diskon − DP`, read-only (field abu-abu/disabled), otomatis dihitung.
- Tombol **"Simpan Payment"** → menyimpan transaksi sebagai **hutang belum lunas**, stok tetap bertambah (barang sudah diterima secara fisik meski belum lunas dibayar), dan invoice ini muncul di daftar "Data Hutang Belum Lunas" (lihat 5.2a).

### 5.2 Sub-menu: Invoice Pembelian

Daftar riwayat transaksi pembelian, terpisah jadi 2 halaman/tab sesuai status pelunasan. Keduanya pakai komponen tabel yang sama (mirip DataTables): dropdown **"Show [10] entries"**, kotak **Search**, kolom bisa di-sort (ikon panah atas-bawah di header), dan footer paginasi **"Showing X to Y of Z entries"** + tombol Previous/Next.

**a. Data Hutang Belum Lunas** (dari transaksi tab Hutang yang Sisa Hutang-nya masih > 0)
- Judul halaman: **"Data Hutang Belum Lunas"**.
- Kolom: **No, Invoice, Tanggal Transaksi, Supplier, Jatuh Tempo, Sub Total, Aksi**.
- Aksi per baris — **5 ikon**: **Lihat detail** (mata, teal), **Bayar/Lunasi** (ikon nominal, hijau — buka form kecil untuk input nominal pembayaran cicilan/pelunasan, mengurangi Sisa Hutang; kalau Sisa Hutang jadi 0, invoice otomatis pindah ke daftar "Data Pembelian" lunas), **Edit** (pensil, biru), **Print** (printer, kuning), **Hapus** (tempat sampah, merah).
- Highlight visual untuk invoice yang **jatuh tempo sudah lewat** (mis. baris berwarna merah/kuning), supaya user gampang lihat mana yang perlu segera dibayar — ini belum ada di referensi tapi diusulkan sebagai enhancement karena datanya (kolom Jatuh Tempo) sudah tersedia.

**b. Data Pembelian (Lunas/Cash)**
- Judul halaman: **"Data Pembelian"**, subjudul tabel **"Data barang Pembelian"**.
- Kolom: **No, Invoice, Tanggal, Supplier, Total, Bayar, Kembali, Aksi**.
- Aksi per baris — **4 ikon** (tanpa ikon "Bayar/Lunasi" karena sudah lunas): **Lihat detail** (mata, biru), **Edit** (pensil, kuning), **Print** (printer, hijau), **Hapus** (tempat sampah, merah).
- Berisi transaksi dari tab Cash (langsung lunas saat disimpan) **dan** transaksi Hutang yang sudah dilunasi penuh dari daftar (a) — begitu Sisa Hutang = 0, baris tersebut pindah dari (a) ke (b) secara otomatis, format kolomnya menyesuaikan (Jatuh Tempo & Sub Total di (a) berubah jadi Total/Bayar/Kembali di (b)).

### 5.3 Import Pembelian via Excel

Sama seperti pola "Upload Transaksi Excel" pada referensi (dan mengikuti pola Super Kasir untuk penjualan di 4.2/8), sediakan **Download Template Excel** dan **Upload Transaksi Excel** khusus pembelian, untuk input banyak transaksi pembelian sekaligus tanpa entri manual satu-satu.

**Kolom template import pembelian (usulan, konsisten dengan pola REF_TRANSAKSI di Super Kasir):**

| Kolom | Keterangan |
|---|---|
| `REF_PEMBELIAN` | Pengelompokan baris ke 1 invoice yang sama (nomor invoice final tetap dibuat/diedit otomatis oleh sistem) |
| `TANGGAL_WAKTU` | Format `YYYY-MM-DD HH:MM` |
| `SUPPLIER` | Nama atau ID supplier, harus sudah terdaftar di master supplier |
| `JENIS_TRANSAKSI` | `CASH` atau `HUTANG` |
| `BAYAR_DP` | Untuk CASH = nominal bayar (boleh kosong = bayar pas). Untuk HUTANG = nominal DP (boleh kosong/0) |
| `JATUH_TEMPO` | Hanya untuk HUTANG, boleh kosong (otomatis = akhir bulan ketiga setelah bulan transaksi) |
| `KODE_BARANG` | Wajib sudah ada di master barang |
| `QTY` | Jumlah barang dibeli |
| `HARGA_BELI` | Wajib diisi (beda dengan penjualan, harga beli tidak boleh kosong karena ini yang jadi dasar hitung margin) |
| `DISKON_ITEM` | Nominal Rp, opsional, per baris — sesuai kolom "Diskon Item" di form manual (5.1), mengikuti struktur e-Faktur |
| `DISKON_TOTAL` | Nominal Rp, opsional, diisi di salah satu baris per grup `REF_PEMBELIAN` (berlaku untuk keseluruhan invoice tsb) — setara kolom "Diskon/Potongan" di form manual (5.1) |

**Aturan validasi** (pola sama dengan template Super Kasir di 4.2/8, demi konsistensi UX): satu file boleh banyak invoice, tiap invoice boleh banyak baris barang; metadata dalam 1 `REF_PEMBELIAN` wajib konsisten; kalau 1 baris gagal validasi, seluruh file dibatalkan dan tidak ada stok yang berubah; ada batas maksimal baris per file (disesuaikan kapasitas shared hosting, lihat 1.1).

### 5.4 Dampak ke Stok & Harga

- Setiap transaksi pembelian yang **disimpan** (baik Cash langsung, maupun Hutang — karena barang sudah diterima fisik) otomatis **menambah qty** di Kartu Stok Masuk per `KODE_BARANG`, tercatat dengan tanggal transaksi pembelian sebagai tanggal masuk stok.
- Harga beli dari transaksi ini men-trigger **perhitungan ulang harga jual** sesuai margin & mode harga (otomatis/kunci manual) per SKU, sesuai logika di bagian 4.1.
- Riwayat harga beli per pembelian (termasuk yang harganya beda-beda antar batch) tersimpan di `histori_harga` (4.1d), inilah yang nanti dipakai algoritma Generate Transaksi (bagian 6) untuk tahu harga jual yang berlaku di tanggal tertentu.

### 5.5 Dampak Edit & Hapus Invoice Pembelian ke Stok & Harga

5.2 sudah menyebut aksi **Edit** dan **Hapus** di daftar Invoice Pembelian, tapi belum dijelaskan dampaknya ke stok — ini penting karena kalau tidak diatur, stok bisa jadi tidak sinkron dengan histori transaksi. Aturannya:

**a. Edit invoice pembelian** (ubah qty/harga per baris, atau tambah/hapus baris item)
- Dilakukan sebagai **1 operasi atomik** (DB transaction): sistem dulu **membatalkan efek lama** invoice ini (kurangi balik qty yang sudah ditambahkan ke stok, hapus baris `histori_harga` yang berasal dari invoice ini), baru **menerapkan efek baru** sesuai data hasil edit (tambah stok & catat histori harga baru) — supaya tidak ada jejak ganda atau bolong.
- **Ditolak** (muncul pesan error, edit tidak disimpan) kalau qty baru untuk 1 item lebih kecil dari qty yang **sudah terjual/keluar** dari item tsb sejak invoice ini disimpan — karena itu akan membuat stok jadi minus. Sistem tampilkan berapa qty yang sudah terlanjur keluar, supaya user tahu batas minimal qty yang boleh diedit.

**b. Hapus invoice pembelian**
- Sama seperti edit: kurangi balik qty dari stok & hapus baris `histori_harga` terkait.
- Kalau qty yang mau dikurangi balik **lebih besar dari stok yang tersisa saat ini** (artinya sebagian barang dari invoice ini sudah terjual), tampilkan **warning** sebelum hapus, dengan 2 opsi (pola sama dengan warning kapasitas stok di bagian 6 poin 3): **lanjutkan hapus** (stok akan jadi minus, ditandai jelas di Kartu Stok sebagai anomali untuk investigasi manual) atau **batalkan**.
- **Invoice Hutang yang sudah punya histori pembayaran** (DP atau cicilan dari mekanisme pelunasan 5.2a): Hapus **diblokir total** selama masih ada baris pembayaran terkait — user harus menghapus/membatalkan dulu histori pembayarannya secara eksplisit, supaya tidak ada catatan pembayaran yang "mengambang" tanpa invoice induk.

---

## 6. Algoritma Generate Transaksi Otomatis

### Input
- `target_omset` (Rp)
- `periode` (tanggal mulai – selesai)
- `daftar_stok_masuk` per hari dalam periode (+ sisa stok dari periode sebelumnya)
- `transaksi_manual_existing` (agar tidak dihitung dobel dalam target)

### Langkah Proses

1. **Hitung sisa target** = `target_omset – total_transaksi_manual_yang_sudah_ada`.
2. **Bangun timeline ketersediaan stok** per hari (running balance): stok tersedia hari H = stok masuk s.d. hari H − stok yang sudah terpakai (baik dari transaksi manual maupun hasil generate sebelumnya).
3. **Cek kapasitas maksimum**: hitung total nilai jual seluruh stok yang tersedia di periode tsb (qty × harga jual). Jika **nilai ini lebih kecil dari `target_omset`**, tampilkan **warning ke user sebelum generate dimulai**, dengan 2 opsi:
   - **Generate semaksimal stok yang ada** (hasil akhir otomatis lebih kecil dari target, sistem tampilkan berapa selisihnya).
   - **Batalkan** proses generate, user bisa menyesuaikan target atau menambah stok dulu.
4. **Bobot distribusi harian**: hari dengan jumlah stok masuk lebih banyak/lebih beragam mendapat "bobot" lebih besar untuk porsi omset hari itu (bisa pakai proporsi qty atau nilai stok masuk sebagai bobot).
5. **Alokasikan omset per hari** = `sisa_target × (bobot_hari / total_bobot)`, dengan tambahan variasi acak kecil (misal ±20%) supaya tidak terlalu rata/kaku dan terlihat natural.
6. **Pecah omset harian menjadi transaksi-transaksi**:
   - Tentukan jumlah transaksi per hari (acak dalam rentang wajar, misal 1–8 nota/hari — bisa dikonfigurasi user).
   - Untuk tiap transaksi, pilih 1–5 item secara acak **hanya dari stok yang masih tersedia** di hari itu, qty acak dalam batas stok.
   - **Khusus barang ber-SN**: qty dipaksa = 1 per baris, dan sistem otomatis memilih satu `NO_SN` spesifik yang statusnya masih "tersedia" (belum terjual) dari referensi SN item tersebut. Satu nomor SN hanya boleh terpakai di satu transaksi.
   - **Khusus barang non-SN**: qty boleh lebih dari 1, field NO_SN dikosongkan.
   - **Harga jual boleh bervariasi** dari harga jual master (mis. simulasi diskon/nego kecil, dalam rentang yang bisa dikonfigurasi, misal −10% s.d. 0% dari harga jual efektif) — supaya hasil generate terlihat wajar, tidak semua transaksi memakai harga persis sama. Variasi ini dicatat sebagai `HARGA_JUAL` per baris di export (lihat 8b), bukan mengubah harga master.
   - Total nilai transaksi = qty × harga jual (setelah variasi) per item.
7. **Validasi stok**: setiap kali 1 transaksi dibuat, kurangi stok "tersedia" secara real-time dalam simulasi — item yang stoknya habis (atau, untuk barang ber-SN, semua nomor SN-nya sudah terpakai) otomatis tidak akan dipilih lagi di hari-hari berikutnya.
8. **Penyesuaian akhir**: jika total hasil generate meleset dari target (karena pembulatan/keterbatasan stok), lakukan penyesuaian pada 1–2 transaksi terakhir agar mendekati target dalam toleransi yang ditentukan (misal ±2%).
9. **Output**: draft transaksi (belum final) → ditampilkan ke user untuk direview.

### Aturan Keras (Hard Constraints)
- ❌ Tidak boleh ada transaksi dengan qty > stok tersedia pada tanggal transaksi.
- ❌ Tidak boleh generate transaksi di tanggal sebelum barang tersebut masuk stok.
- ❌ Tidak boleh generate 2x untuk periode yang sama tanpa menghapus/menimpa draft sebelumnya (harus ada konfirmasi).
- ❌ Barang ber-SN tidak boleh qty > 1 per baris, dan 1 nomor SN tidak boleh dipakai lebih dari 1 kali.
- ❌ Semua baris dalam 1 nota (grup `REF_TRANSAKSI` yang sama) wajib punya metadata konsisten: tanggal/waktu, customer, jenis transaksi (CASH/PIUTANG), dan tipe pembayaran yang sama — mengikuti aturan template import POS Bengkel.
- ❌ Tidak boleh generate transaksi di tanggal yang ditandai **hari libur/tutup toko**, dan jam transaksi hasil generate wajib berada dalam **jam operasional toko** yang dikonfigurasi (lihat 7).
- ❌ Variasi harga jual (poin 6 di atas) tidak boleh membuat harga di bawah harga beli (menghindari transaksi rugi yang tidak masuk akal).
- ✅ Total akhir harus dalam toleransi yang bisa dikonfigurasi (default ±2–5% dari target), **kecuali** kapasitas stok tidak cukup (lihat poin 3 di atas) — dalam kasus itu, hasil akhir memang boleh lebih kecil dari target sesuai persetujuan user.

---

## 7. UX Flow

1. User buka menu **"Generate Transaksi"**.
2. Pilih periode (bulan/tanggal range).
3. Input target omset.
4. (Opsional) Atur parameter: jumlah nota/hari, toleransi %, item yang dikecualikan, **hari libur/tanggal tutup toko** (bisa pilih manual per tanggal atau set hari tertentu tiap minggu, misal semua hari Minggu), dan **jam operasional toko** (jam buka–tutup, default misal 08:00–17:00) — parameter ini disimpan sebagai default toko sehingga tidak perlu diisi ulang tiap generate.
5. Klik **"Generate Draft"** → sistem proses & tampilkan daftar transaksi per hari dalam bentuk tabel (tanggal, item, qty, harga, subtotal, total harian). Jika kapasitas stok tidak cukup untuk mencapai target (lihat 6, langkah 3), tampilkan warning + pilihan lanjut/batalkan sebelum draft ditampilkan.
6. User bisa:
   - Edit manual per baris (ubah item/qty/harga).
   - Hapus transaksi tertentu.
   - Klik **"Generate Ulang"** jika hasil kurang sesuai.
7. Klik **"Simpan"** → transaksi masuk ke pembukuan resmi & stok terpotong permanen.
8. Sistem menandai batch ini dengan label `sumber: rekonstruksi` (berbeda dari transaksi kasir real-time) untuk keperluan audit internal.

---

## 8. Fitur Export Transaksi

Setelah transaksi tersimpan (baik hasil generate maupun input manual biasa), user perlu bisa export ke file. Ada **dua jenis format export**: (A) format yang **langsung bisa diimport ke POS Bengkel** — ini prioritas utama, dan (B) format laporan/arsip internal biasa.

### a. Filter (berlaku untuk kedua format)
- **Berdasarkan rentang tanggal** (dari tanggal – sampai tanggal, bebas dipilih).
- **Berdasarkan bulan** (shortcut: pilih 1 bulan penuh, misal "Juni 2026").
- Filter tambahan (opsional, nice-to-have): per kategori item, per sumber transaksi (`manual` / `rekonstruksi`), per rentang nominal.

### b. Format A — Export "Siap Import ke POS Bengkel" (Prioritas Utama)

Mengikuti persis struktur template **Super Kasir** (sheet `IMPORT_PENJUALAN`) milik aplikasi POS Bengkel, supaya file hasil export bisa langsung di-upload tanpa edit manual. Karena tidak ada API import (lihat 4.2), file inilah satu-satunya jembatan data antara aplikasi ini dan POS Bengkel — jadi akurasinya kritikal.

**Mapping kolom (data internal aplikasi ini → kolom template POS Bengkel), versi v1 — nilai default ini disimpan sebagai konfigurasi, bukan hardcode (lihat 4.2):**

| Kolom Template | Sumber Data di Aplikasi Ini | Catatan |
|---|---|---|
| `REF_TRANSAKSI` | ID batch/nota internal | Semua baris item dalam 1 nota memakai nilai yang sama, hanya untuk pengelompokan — nomor nota final tetap dibuat otomatis oleh POS Bengkel |
| `TANGGAL_WAKTU` | Tanggal & jam transaksi hasil generate | Format wajib `YYYY-MM-DD HH:MM`, contoh `2026-06-05 13:30` |
| `CUSTOMER_ID` | Selalu `0` (Customer Umum) | Keputusan tetap — semua transaksi hasil generate pakai Customer Umum, tidak ada mapping ke customer lain di v1 |
| `JENIS_TRANSAKSI` | Default `CASH` | Bisa diubah ke `PIUTANG` kalau memang direkonstruksi sebagai piutang |
| `TIPE_PEMBAYARAN` | Default `CASH` | Alternatif `TRANSFER`, sesuai kebutuhan |
| `BAYAR_DP` | Dikosongkan | Supaya POS Bengkel menganggap pembayaran pas (sesuai aturan template) |
| `JATUH_TEMPO` | Dikosongkan (kecuali `JENIS_TRANSAKSI = PIUTANG`) | Hanya relevan untuk piutang |
| `KODE_BARANG` | `kode_barang` di master SKU aplikasi ini | **Wajib identik** dengan REFERENSI_BARANG POS Bengkel (lihat 4.2) |
| `QTY` | Qty hasil generate per baris | Wajib `1` kalau barang ber-SN |
| `HARGA_JUAL` | Harga jual efektif saat tanggal transaksi | Boleh dikosongkan agar POS Bengkel pakai harga master saat import — tapi **sebaiknya tetap diisi** dari histori harga (lihat 4.1c), supaya harga yang tercatat sesuai kondisi saat tanggal transaksi tsb, bukan harga master hari ini |
| `NO_SN` | Nomor SN yang dipilih algoritma (bagian 6) | Wajib diisi untuk barang ber-SN, wajib kosong untuk non-SN |

> Kolom, urutan, dan nilai default di atas diambil dari pengaturan yang bisa diubah admin (halaman **Pengaturan → Template Export POS**, lihat 4.2), bukan ditulis tetap di kode — supaya kalau template Super Kasir berubah versi di kemudian hari, aplikasi ini tinggal disesuaikan pengaturannya tanpa perlu ubah kode program.

**Aturan validasi yang wajib dipatuhi saat generate file ini** (mengikuti ketentuan template Super Kasir):
- Satu file bisa memuat banyak `REF_TRANSAKSI`, tiap `REF_TRANSAKSI` bisa memuat banyak baris barang.
- Semua baris dengan `REF_TRANSAKSI` sama wajib punya metadata (tanggal, customer, jenis transaksi, tipe pembayaran) yang konsisten.
- **Maksimal 5.000 baris barang per file** — kalau hasil generate 1 bulan melebihi batas ini, sistem **otomatis memecah jadi beberapa file** (mis. `export-juni-2026-part1.xlsx`, `part2.xlsx`, dst.), bukan menolak export.
- Sistem melakukan **pre-validasi** sebelum file diunduh: cek semua `KODE_BARANG` ada di daftar referensi barang, barang ber-SN qty = 1 dan NO_SN terisi valid, barang non-SN NO_SN kosong — supaya kecil kemungkinan file ditolak oleh POS Bengkel (yang membatalkan seluruh file kalau 1 baris gagal validasi).
- Sertakan juga sheet `PETUNJUK` ringkas di file export (opsional) yang menjelaskan bahwa data ini hasil generate dari aplikasi rekonstruksi omset.

### c. Format B — Export Laporan/Arsip Internal

Untuk kebutuhan laporan biasa (bukan untuk diimport balik), format lebih sederhana dan mudah dibaca manusia:

| Kolom | Keterangan |
|---|---|
| Tanggal Transaksi | |
| No. Nota/ID Transaksi | |
| Nama Item | |
| Qty | |
| Harga Satuan | |
| Subtotal | |
| Total per Nota | |
| Sumber | `manual` atau `rekonstruksi` |

- Format file: **Excel (.xlsx)** default, alternatif **CSV**, opsional **PDF** (fase 2).
- Tambahkan baris **ringkasan** di akhir file: total omset periode, jumlah nota, jumlah item terjual.

### d. Pertimbangan Teknis (Shared Hosting)
- Karena resource shared hosting terbatas, export data dalam jumlah besar (misal export 1 tahun penuh) sebaiknya:
  - Diproses per-halaman/batch (paging query), bukan load semua data ke memory sekaligus.
  - Ada indikator progress/loading, dan batasi wajar (misal maksimal export 1 tahun per request, otomatis dipecah per bulan) untuk menghindari timeout `max_execution_time`.
- Gunakan library ringan seperti **PhpSpreadsheet** (untuk .xlsx) yang kompatibel dengan shared hosting PHP standar — juga dipakai untuk generate file sesuai struktur template Super Kasir di Format A.

---

## 9. Kebutuhan Non-Fungsional

- **Reversible**: sebelum tombol "Simpan" ditekan, semua hasil generate harus berstatus draft (bisa dibuang tanpa efek ke stok).
- **Audit trail**: setiap batch generate tercatat kapan digenerate dan parameter yang dipakai (tidak perlu tracking multi-user, lihat catatan akses di bawah).
- **Performa**: generate untuk 1 bulan penuh (±30 hari, ratusan item) harus selesai < 5 detik.
- **Konfigurasi**: parameter (jumlah nota/hari, toleransi, item dikecualikan, hari libur, jam operasional) disimpan sebagai default yang bisa diubah per toko.
- **Akses/Role**: aplikasi ini **hanya dipakai oleh 1 akun admin** (pemilik toko), tidak ada kebutuhan multi-role atau multi-user di v1. Login tetap wajib ada untuk keamanan (lihat 1.1), tapi tidak perlu sistem role/permission bertingkat — cukup satu akun dengan akses penuh ke semua fitur.

---

## 10. Keputusan yang Sudah Diambil

| Topik | Keputusan |
|---|---|
| Integrasi ke POS Bengkel | **Manual via file** — POS Bengkel tidak punya API import, jadi alur tetap: generate & export di aplikasi ini → user unduh file → user upload manual ke Super Kasir POS Bengkel (detail: 4.2) |
| `CUSTOMER_ID` | **Selalu `0` (Customer Umum)** untuk semua transaksi hasil generate, tidak ada mapping ke customer lain di v1 |
| Mapping kolom template Super Kasir | **Konfigurabel**, disimpan sebagai pengaturan (bukan hardcode di kode), lewat halaman Pengaturan → Template Export POS, supaya mudah disesuaikan kalau POS Bengkel merilis versi template baru (detail: 4.2) |
| Harga jual saat generate | **Boleh bervariasi** dari harga master (simulasi diskon/nego kecil), bukan harga tetap kaku — dicatat per baris transaksi (detail: 6) |
| Target omset melebihi kapasitas stok | Sistem **tampilkan warning** sebelum generate, dengan 2 opsi: lanjut generate semaksimal stok yang ada, atau batalkan (detail: 6) |
| Hari libur & jam operasional toko | **Dimasukkan sebagai parameter** — tanggal tutup toko dan jam buka/tutup bisa diatur, dan generate transaksi wajib mematuhi keduanya (detail: 6, 7) |
| Komponen jasa service (non-stok) | **Belum dibutuhkan di v1**, tapi struktur data disiapkan agar mudah ditambahkan di versi berikutnya tanpa perombakan besar (detail: 2) |
| Role & permission | **Cukup 1 akun admin** — aplikasi ini hanya dipakai oleh pemilik toko sendiri, tidak perlu sistem role/permission bertingkat (detail: 9) |
| Diskon per barang di Transaksi Pembelian | **Ditambahkan sebagai kolom "Diskon Item"** di tabel item pembelian (5.1), meski tidak ada di referensi aplikasi POS Bengkel lama — keputusan ini diambil untuk menyesuaikan struktur **e-Faktur** (diskon dicatat per baris item, baru diskon tambahan di level invoice). Berlaku juga di template import Excel lewat kolom `DISKON_ITEM` (detail: 5.1, 5.3) |
| Sumber data master item (`KODE_BARANG`, harga, stok) | **Satu sumber tunggal**: menu Data Barang (4.3). Field Harga Beli & Kode Barang **tidak bisa diedit manual** di form ini setelah dibuat — Harga Beli hanya berubah lewat transaksi Pembelian (5.1/5.4), Kode Barang terkunci permanen. Field Stock **dibatasi**, hanya bisa berubah lewat jalur Stok Awal (barang baru) atau Stok Opname (form terpisah, wajib alasan, tercatat di Kartu Stok) — tidak bisa diketik bebas di form Edit biasa (detail: 4.3) |

---

## 11. Metrik Keberhasilan

- Selisih rata-rata antara omset target vs hasil generate < 5%.
- 0 kasus stok minus akibat hasil generate.
- Waktu yang dihemat user dibanding input manual satu-satu (target: >80% lebih cepat).

---

## 12. Lampiran: Struktur Data (Ringkasan Skema Database)

Field-field di dokumen ini tersebar di banyak section — lampiran ini merangkumnya jadi daftar tabel inti, supaya jadi acuan cepat saat membuat migration/model. **Ini ringkasan, bukan DDL final** (tipe data presisi, index, dan constraint detail tetap ditentukan saat implementasi).

| Tabel | Field Inti | Keterangan / Dirujuk di |
|---|---|---|
| `users` | id, name, email, password (hash), last_login_at | 1 akun admin (1.2, 9) |
| `toko_settings` | id, nama_toko, alamat, telepon, logo, margin_default, jam_buka, jam_tutup, hari_libur (JSON), ambang_stok_menipis, parameter_generate (JSON: jumlah_nota_per_hari, toleransi_persen, rentang_variasi_harga) | 1 baris per toko (4.5) |
| `kategori_barang` | id, nama | Dropdown Kategori (4.3) |
| `barang` | id, kode_barang (unique), nama, deskripsi, kategori_id (FK), satuan, sn_atau_non_sn (enum), harga_beli_terakhir, margin_override (nullable), mode_harga_jual (enum: otomatis/manual_terkunci), harga_jual, stock, lokasi_rak, status (enum) | Master item (4.3), field detail di 4.1d |
| `histori_harga` | id, barang_id (FK), harga_beli, margin_dipakai, harga_jual_efektif, tanggal_berlaku, invoice_pembelian_id (FK, nullable) | Log per SKU (4.1d), dipakai algoritma generate (6) |
| `referensi_sn` | id, barang_id (FK), no_sn, status (enum: tersedia/terjual), invoice_pembelian_id (FK, asal masuk), ref_transaksi_penjualan (nullable, kapan terjual) | Barang ber-SN (4.2, 6) |
| `supplier` | id, nama, kontak, email, alamat, catatan | Master supplier (4.4) |
| `invoice_pembelian` | id, no_invoice, supplier_id (FK), tanggal_transaksi, jenis (enum: cash/hutang), diskon_total, subtotal, total_setelah_diskon, bayar_atau_dp, jatuh_tempo (nullable), sisa_hutang, status (enum: lunas/belum_lunas), sumber (enum: manual/import_excel) | Header transaksi pembelian (5.1, 5.2) |
| `invoice_pembelian_item` | id, invoice_pembelian_id (FK), barang_id (FK), harga_beli, qty, diskon_item, sub_total, no_sn (nullable) | Baris item pembelian (5.1) |
| `pembayaran_hutang` | id, invoice_pembelian_id (FK), tanggal_bayar, nominal, catatan | Mekanisme pelunasan cicilan (5.2a, 5.5) |
| `kartu_stok` | id, barang_id (FK), tanggal, jenis (enum: masuk_pembelian/keluar_penjualan/stok_awal/opname), qty (+/−), referensi_id (nullable, ke invoice_pembelian atau transaksi_penjualan), keterangan | Mutasi stok gabungan (4, 4.3a, 5.5) |
| `transaksi_penjualan` | id, ref_transaksi, tanggal_waktu, customer_id (selalu 0), jenis_transaksi (enum: cash/piutang), tipe_pembayaran, sumber (enum: manual/rekonstruksi), batch_generate_id (FK, nullable) | Header hasil generate/manual (6, 8) |
| `transaksi_penjualan_item` | id, transaksi_penjualan_id (FK), barang_id (FK), qty, harga_jual, no_sn (nullable), subtotal | Baris item penjualan (6, 8b) |
| `batch_generate` | id, periode_mulai, periode_selesai, target_omset, parameter_json, status (enum: draft/tersimpan), created_at | Audit trail generate (9) |
| `template_export_pos_config` | id, nama_kolom, urutan, wajib_opsional, format, nilai_default, template_version | Mapping konfigurabel (4.2) |
| `export_batch` | id, tanggal_export, filter_periode, jumlah_file, status_upload_manual (boolean), created_at | Histori & status upload ke POS (4.2) |

