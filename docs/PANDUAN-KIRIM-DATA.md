# Panduan Kirim Data Lokal → Production (via Backup)

> Prinsip: kode dikirim dulu (`deploy.sh`, agar migrasi berjalan), **data belakangan**.
> Production hanya untuk dilihat; satu-satunya penginput dari lokal.

## 0. Hasil uji restore MySQL (2026-10-01, instance sementara, sudah dihapus)

- Instance MySQL **8.4.11 terisolasi** (datadir+socket /tmp, `--skip-networking`, port TCP mati) — server brew utama tak tersentuh.
- Backup SQLite (3.704 baris) → restore ke MySQL kosong: **18/18 tabel jumlah baris PERSIS sama**, 0 orphan item/kartu (id & relasi utuh), format tanggal benar, insert baru dapat id lanjut (sequence diperbaiki otomatis).
- File backup: `uji-mysql-20261001-222852.json.gz` = **63.851 byte** (±62KB).
- **Batasan temuan**: uji ini membuktikan MySQL 8.4 lokal. Bila hosting memakai **MariaDB / versi berbeda**, cek dulu versinya (hPanel → Databases → info server) sebelum restore pertama; format JSON toleran, tapi laporkan versinya bila restore menolak.

## 1. Cara Backup menangani beda SQLite vs MySQL

- Baris dibaca sebagai array asosiatif lalu `json_encode`: tanggal jadi string `Y-m-d H:i:s`, boolean jadi 0/1 — diterima kedua driver.
- Urutan FK dijamin daftar `TABEL` (`BackupService::TABEL`): hapus anak-dulu, insert induk-dulu, per chunk 500.
- `id` asli ikut disimpan; sesudah commit, `perbaikiSequence()` menyetel `sqlite_sequence` / `AUTO_INCREMENT` per driver.
- Yang **tidak ikut**: `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, dan tabel `migrations` (riwayat migrasi hosting utuh). Tabel `users` **ikut** — setelah restore, login hosting = login lokal.

## 2. Batas ukuran, waktu, dan skenario terputus

- Upload backup divalidasi `max:20480` (**20MB**); file saat ini ±62KB — sangat longgar. Catatan: PHP laptop `upload_max_filesize=8M`; samakan/cek nilai setara di hPanel → PHP Configuration bila suatu hari file > batas.
- Restore = **satu transaksi DML** (pra-backup `pra-restore-*` dibuat SEBELUM transaksi; rollback otomatis bila gagal; `TRANSIENT` + sequence DDL **sesudah** commit).
- Bila terputus: saat upload/pratinjau = aman (read-only); di dalam transaksi = rollback + pra-backup utuh; sesudah commit sebelum sequence = data benar, sequence basi → pulihkan dengan **restore ulang** (idempoten).
- Recovery: menu Backup di hosting → restore file `pra-restore-*` terbaru.

## 3. Langkah pengiriman (berurutan, production hanya setelah setuju per langkah)

1. Di lokal: `git status` bersih; jalankan `deploy.sh` dulu (kode + migrate terbaru).
2. Di lokal menu Backup → **Buat Backup** → **Unduh** file `.json.gz` (catat nama & jumlah baris).
3. Di hPanel File Manager: upload file ke hosting (atau via SFTP); catat juga versi DB hosting (langkah 0).
4. Di hosting menu Backup: **Buat Backup** sekali (cadangan tangan kedua) → **Unggah** file lokal → **Pratinjau** (bandingkan per tabel) → ketik **RESTORE**.
5. Verifikasi: login pakai kredensial lokal, cek Dashboard/Rekap/publik.
6. Rollback bila salah: restore file `pra-restore-*` (otomatis dibuat langkah 4).
