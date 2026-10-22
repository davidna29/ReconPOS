# Temuan: Kesiapan ReconPOS sebagai Aplikasi Akuntansi & Admin Perpajakan CV Bengkel

> Hasil audit menyeluruh (read-only) atas kode, migrasi, dan data aktual.
> Tanggal audit: 2026-10-11 · Basis kode: `36884eb` (setelah fix backup PPh 21).
> Dokumen ini adalah **temuan + definisi minimum**, bukan PRD final.

## 1. Ringkasan Eksekutif

ReconPOS saat ini adalah **POS/stok + pembantu PPh 21 bulanan**, **bukan aplikasi akuntansi**.
Untuk peran "pembantu admin perpajakan CV bengkel", fondasi pembukuan belum ada.

Bukti paling tegas: pencarian `jurnal|buku_besar|neraca|laba_rugi|coa|faktur_pajak|efaktur|bukti_potong|bupot|pph_?23|pasal_17|aset_tetap|penyusutan|spt_`
di seluruh `app/` → **0 hasil**.

| Area | Kesiapan | Catatan singkat |
|---|---|---|
| Akuntansi inti (COA/jurnal/ledger/L-R/neraca) | **0%** | tidak ada sama sekali |
| PPN | **25%** | hanya PPN masukan nominal di pembelian |
| PPh 21 | **70%** | TER + export Coretax; Desember & bukpot belum |
| PPh 23 / 26 / 4(2) | **0%** | tidak ada |
| PPh Badan / PPh 25 | **0%** | tidak ada |
| Aset tetap & penyusutan | **0%** | tidak ada |
| Kas/bank & rekonsiliasi | **~5%** | hanya flag `tipe_pembayaran` cash/transfer |
| Piutang usaha | **~5%** | ada enum `piutang`, tanpa mekanisme |
| Beban operasional (gaji, sewa, listrik) | **0%** | tidak ada modul |
| Kepatuhan/administrasi pajak | **~10%** | tanpa kalender, status lapor, NTPN, arsip |
| Audit trail & peran pengguna | **~10%** | paket activitylog terpasang tapi tak dipakai |
| Master data pajak (NPWP pihak lawan) | **~20%** | supplier tanpa NPWP, tidak ada customer master |

**Kesimpulan: belum memenuhi kriteria aplikasi akuntansi.** Bahkan untuk sekadar "membantu
administrasi pajak" (bukan pembukuan penuh), ada 4 blok wajib yang masih kosong:
**PPN keluaran + e-Faktur, bukti potong, kalender/arsip lapor, dan rekap fiskal.**

## 2. Metode Audit

Diperiksa read-only: `app/Models`, `app/Services`, `app/Http/Controllers`, `app/View/Composers`,
seluruh `database/migrations` (40 file), `resources/views`, `routes/web.php`, serta inventaris
tabel aktual (28 tabel) dibandingkan dengan daftar tabel yang dicakup backup.

## 3. Peta Fitur yang Benar-Benar Ada

| Fitur | Bukti |
|---|---|
| Penjualan: `total` saja, tanpa DPP/PPN | `transaksi_penjualan` (migrasi `2026_08_31_000012`), `TransaksiPenjualan::$fillable` |
| Pembelian: `subtotal`, `diskon_total`, `total_setelah_diskon`, `ppn` (nominal, juga per item) | `invoice_pembelian(+_item)` |
| Tarif PPN configurable 11% (default) | `TokoSetting::ppn_persen` — dipakai **hanya** di `PembelianService::hitungPpnOtomatis` |
| PPN penjualan | **tidak ada** — `grep ppn_persen` tidak menyentuh penjualan; struk hanya menulis "harga sudah termasuk PPN" |
| Stok: `kartu_stok` **tanpa kolom biaya/harga** | migrasi `2026_08_31_000010` |
| Harga beli historis: `histori_harga` (snapshot per barang/tanggal) | migrasi `2026_08_31_000004` |
| PPh 21: TER A/B/C + export xlsx/xml identik Coretax | `Pph21TerService`, `Pph21ExportService` |
| Master karyawan + 5 link berkas + cabang internal | `karyawans` |
| Backup mencakup 3 tabel PPh 21 | `BackupService::TABEL` (21 tabel) |
| "Beban Hutang" = **pemilahan faktur hutang per cabang**, bukan buku beban | `BebanHutangController` |
| Laporan: Format A (impor POS), Format B (daftar penjualan + ringkasan omset/nota/item), PDF | `ExportPosService` |
| Dashboard: omset, hutang, stok kritis, aging, tren | `DashboardComposer` |

## 4. Celah per Area

### A. Fondasi akuntansi — KRITIS
Tidak ada COA, jurnal, buku besar, trial balance, laba rugi, neraca, arus kas.
Dampak: laba fiskal PPh Badan tidak bisa dihitung; pembukuan belum memenuhi standar;
angka PPN/PPh tidak bisa direkonsiliasi ke laporan keuangan.
Bukti: grep 0 hasil; `app/Models` 21 file, tak ada `Jurnal`/`Akun`.

### B. PPN — KRITIS (bila PKP) / TINGGI
1. PPN **keluaran** tidak disimpan → e-Faktur mustahil tanpa back-calculation (`total / 1,11`),
   rawan selisih pembulatan dan salah masa.
2. Tidak ada **rekap SPT Masa PPN** (keluaran − masukan − kompensasi).
3. Tidak ada arsip **faktur pajak / NSFP** dan tanggal faktur.
4. `supplier` **tanpa NPWP** → syarat kredit PPN masukan tidak bisa divalidasi.
5. Tidak ada pencatatan **pembayaran PPN/NTPN**.
6. Tarif default 11% harus diverifikasi terhadap ketentuan yang berlaku (perubahan tarif PPN
   2025 dan sesudahnya); saat ini hanya angka config.

### C. PPh 21 — SEDANG
Sudah baik untuk Jan–Nov. Celah:
1. **Desember** wajib PPh Pasal 17 setahun (neto disetahunkan − PTKP, dikurangi potongan
   Jan–Nov); sekarang masih TER. Workaround tarif manual ada, tapi tidak auditable.
2. **Bukti potong per karyawan** belum ada (hak pegawai, PMK 168/2023).
3. Belum ada **rincian komponen bruto** (gaji/tunjangan/THR/bonus/lembur/natura) — hanya `gross`.
4. `karyawans` tanpa **tanggal masuk/keluar** → tidak bisa hitung bulan efektif/prorating.
5. Belum menangani **bukan pegawai / non-resident 20% / PPh 26** secara terstruktur
   (hanya via tarif manual + `Foreign` + passport).
6. Tidak ada arsip **bukti potong & SPT Masa PPh 21**.

### D. PPh 23 / 26 / 4(2) — KRITIS untuk bengkel
Bengkel lazim membayar jasa mekanik borongan, jasa service pihak ketiga, dan sewa
ruko/tanah/bangunan. Semua objek pemotongan ini tidak ada modulnya, tidak ada bukti potong,
tidak ada rekap masa.

### E. PPh Badan & PPh 25 — KRITIS
Tidak ada laba fiskal, koreksi fiskal (natura, penyusutan, biaya non-deductible),
angsuran PPh 25, atau rekap tahunan. Tanpa ini, "admin perpajakan" hanya sampai level PPh 21.

### F. Aset tetap & penyusutan — TINGGI
Tidak ada register aset (lift, kompresor, tools, kendaraan), umur manfaat, metode
garis lurus/saldo menurun, penyusutan fiskal vs komersial. Laba fiskal akan salah.

### G. Kas/bank — TINGGI
Tidak ada akun kas/bank, mutasi, saldo, atau rekonsiliasi bank.
`tipe_pembayaran` hanya label.

### H. Piutang usaha — TINGGI
`jenis_transaksi` punya nilai `piutang`, tetapi tidak ada tabel piutang, pelunasan,
aging, atau jatuh tempo penjualan.

### I. HPP/COGS akurat — TINGGI
`kartu_stok` tidak menyimpan biaya per mutasi → HPP FIFO per nota **tidak bisa** dihitung
dari ledger. `histori_harga` hanya snapshot per barang/tanggal → margin hanya bisa didekati.

### J. Beban operasional — KRITIS
Tidak ada modul beban (gaji, sewa, listrik, BBM, pemeliharaan). Tanpa beban, laba/rugi
tidak mungkin disusun.
**Catatan penting:** menu **"Beban Hutang" bukan buku beban** — ia hanya memilah faktur
hutang pembelian per cabang.

### K. Administrasi kepatuhan — TINGGI
Tidak ada: kalender jatuh tempo, status lapor per masa, nomor NTPN/bukti bayar,
pengingat, hitung sanksi/bunga, checklist arsip.

### L. Audit trail & peran — SEDANG
`spatie/laravel-activitylog` terpasang di `composer.json` tetapi **tidak dipakai** satu model
pun. `users` tanpa role; tidak ada jejak "siapa mengubah apa" — penting saat pemeriksaan pajak.

### M. Master data pajak pihak lawan — TINGGI
`supplier`: tanpa NPWP/NIK, tanpa status PKP. Tidak ada master customer (hanya `customer_id`
default 0) → tidak bisa menerbitkan faktur pajak atas nama pembeli.

### N. Catatan kepatuhan atas data rekonstruksi — PERLU KEBIJAKAN
Fitur Generate membuat transaksi sintetis untuk mencapai target omset. Untuk pelaporan pajak,
angka tersebut butuh bukti pendukung riil (nota, mutasi bank, stok keluar). Perlu kebijakan
tertulis agar laporan tidak berbenturan saat pemeriksaan.

## 5. Temuan Teknis/Bug yang Muncul Saat Audit

| # | Temuan | Bukti | Dampak |
|---|---|---|---|
| 1 | `$trx->jatuh_tempo` diakses padahal kolom tidak ada di `transaksi_penjualan` | `ExportPosService.php:343` | jatuh tempo piutang selalu kosong (dead code) |
| 2 | `jenis_transaksi = 'piutang'` diterima, tanpa mekanisme pelunasan/aging | migrasi penjualan + grep `piutang` | piutang tidak terlacak |
| 3 | `ppn_persen` tidak dipakai di jalur penjualan | grep | PPN keluaran tidak pernah dihitung |
| 4 | `kartu_stok` tanpa biaya | migrasi | HPP tidak akurat |
| 5 | Tarif PPN default 11% di-hardcode sebagai fallback di beberapa tempat | `PembelianService`, `PengaturanController`, view | risiko tarif usang |
| 6 | Activity log terpasang tapi tak aktif | `composer.json` vs grep | tidak ada audit trail |
| 7 | Backup sempat melewatkan 3 tabel PPh 21 | commit `36884eb` | **sudah diperbaiki**; aturan: tabel baru wajib didaftarkan |

## 6. Definisi "Minimal" (MVP) untuk Admin Perpajakan CV Bengkel

### Tier 1 — wajib agar benar-benar membantu (tanpa double-entry penuh)
1. PPN: simpan DPP + PPN keluaran per nota; rekap masa PPN (keluaran, masukan, kurang/lebih
   bayar); export CSV e-Faktur; arsip NSFP.
2. NPWP/NIK pada supplier & customer; status PKP.
3. PPh 21 Desember (Pasal 17 tahunan) + bukti potong PDF per karyawan + rincian komponen
   bruto + tanggal masuk/keluar.
4. PPh 23/26 & 4(2): pemotongan, bukti potong, rekap masa.
5. Kalender & arsip kepatuhan: status lapor/bayar per masa, NTPN, pengingat, ekspor bukti.
6. Beban operasional sederhana (kategori + nominal + lampiran) agar laba/rugi dasar mungkin.
7. Audit trail (aktifkan activitylog) + peran pengguna minimal (admin/operator).

### Tier 2 — akuntansi sesungguhnya
8. COA + jurnal otomatis dari transaksi (penjualan, pembelian, stok, PPN, PPh, beban) +
   buku besar + trial balance.
9. Laba rugi & neraca; HPP akurat (simpan biaya di `kartu_stok` → FIFO bernilai).

### Tier 3 — pajak lanjutan
10. Aset tetap + penyusutan fiskal/komersial.
11. PPh Badan: laba fiskal, koreksi fiskal, PPh 25, rekap SPT Tahunan.
12. Kas/bank + rekonsiliasi; piutang + aging.

**Estimasi indikatif** (relatif, bukan janji): Tier 1 ≈ 8–12 unit kerja; Tier 2 ≈ 6–10;
Tier 3 ≈ 6–10. Urutan disarankan **Tier 1 → Tier 2**, karena Tier 1 memberi manfaat langsung
dan datanya menjadi bahan jurnal di Tier 2.

## 7. Acceptance Criteria MVP

- Nota penjualan menyimpan DPP & PPN; total = DPP + PPN; rekap masa cocok dengan penjumlahan nota.
- CSV e-Faktur lolos validasi struktur impor e-Faktur (kolom, format tanggal & nominal).
- PPh 21 Desember menghasilkan nominal berbeda dari TER (bukti rekonsiliasi tahunan) dan dapat
  dicetak per karyawan.
- Setiap pemotongan PPh 23/4(2) menghasilkan bukti potong bernomor dan masuk rekap masa.
- Halaman kepatuhan menampilkan status per masa (belum/sudah lapor, belum/sudah bayar + NTPN).
- Laba rugi sederhana = omset − HPP − beban, dapat ditelusuri ke transaksi sumber.
- Setiap perubahan data pajak tercatat di activity log.

## 8. Pertanyaan Terbuka (perlu jawaban sebelum desain final)

1. Status **PKP** CV: sudah dikukuhkan atau belum? (menentukan wajib/tidak e-Faktur & PPN)
2. Omzet setahun di atas Rp4,8 M? (pembukuan vs pencatatan)
3. Lokasi TB **sewa** atau milik sendiri? Ada pembayaran jasa pihak ketiga rutin?
4. **PPh Badan** dihitung internal atau konsultan? (menentukan kedalaman Tier 3)
5. Butuh **multi-cabang** dengan NPWP terpisah, atau satu NPWP (seperti sekarang)?
6. Prioritas: mulai Tier 1 (pajak langsung terasa) atau Tier 2 (pembukuan) dulu?
