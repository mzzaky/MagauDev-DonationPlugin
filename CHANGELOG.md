# Changelog

## [Belum Dirilis]

Belum ada perubahan.

## [1.0.6] - 2026-10-31

### Ditambahkan

- **Hadiah donasi (XP dan koin)**: konfigurasi `rewards` pada pengaturan (bawaan nonaktif, tanpa tingkat contoh tersimpan) berisi maksimal 12 tingkat berdasarkan satu donasi (`single`, hanya tingkat tertinggi) atau akumulasi dukungan member (`total`, setiap ambang yang baru terlewati sekali). Tabel `rewards` dengan kunci unik `(attempt_id, tier_id)` dan `(tier_id, owner_key)`; status `pending_claim|pending|granted|failed|skipped`. Hanya produksi dan hanya member; tamu menunggu klaim riwayat. Penyerahan lewat filter `mgdd_reward_grant` (adapter tema), registri tipe lewat `mgdd_reward_types`, percobaan ulang dengan backoff pada rekonsiliasi dan tombol admin.
- **Donatur manual**: entri `source='manual'` pada tabel `attempts` dengan event penyesuaian append-only; ubah nominal menambah event selisih, hapus berarti void (event pembalik, `voided_at`, jejak audit). Pencarian member (`GET /admin/member-search`), tautan member opsional, atestasi persetujuan publik, kunci idempotensi, deteksi konflik revisi 409, kapabilitas baru `mgdd_manual`.
- Endpoint `GET /recent` (dinding pendukung), `GET/POST /admin/rewards`, `GET /admin/rewards/log`, `POST /admin/rewards/{id}/retry`, `POST /admin/manual`, `/admin/manual/{id}`, `/admin/manual/{id}/void`, `POST /admin/donors/{id}/email` (ungkap email, diaudit) dan `/admin/donors/{id}/anonymize`. `GET /config` memuat tangga hadiah tersaring hanya saat aktif; `GET /status/{ref}` memuat `reward` untuk pemilik.
- Daftar donatur menampilkan email tersamar, penanda manual dan total; kolom `source` pada detail dan ekspor CSV.

### Diubah

- Skema plugin 1 → 2 (idempoten, non-destruktif): kolom `attempts.source|voided_at|reward|revision`, `donors.is_manual`, `intents.note`, tabel `rewards`. Teks FAQ bawaan tidak lagi menyatakan donasi tidak memberi XP/koin; FAQ tersimpan hanya diganti bila identik dengan bawaan 1.0.5.
- Rekonsiliasi dan kwitansi otomatis hanya untuk entri gateway; sinkronisasi entri manual ditolak. Peringkat, ringkasan, pesan dan dinding menyertakan entri manual yang sah dan mengecualikan yang dibatalkan tanpa membocorkan asal entri.
- Privasi: ekspor dan penghapus mencakup entri manual dan baris hadiah; catatan internal dihapus saat anonimisasi.

### Keamanan

- Evaluasi hadiah berjalan di luar transaksi dan kunci ledger, tidak pernah menggagalkan pembayaran atau webhook, dan idempoten (kunci unik tabel, kunci per hadiah, kunci ledger tema). Refund sesudah hadiah hanya menghasilkan peringatan audit; ledger tidak menarik hadiah otomatis.
- Semua endpoint baru memeriksa kapabilitas, nonce dan rate limit di server; pencarian member tidak mengembalikan email.

### Sistem dan versi

- Header plugin, `MGDD_VERSION` dan paket ke 1.0.6; tema minimum 1.0.6 untuk halaman/panel/hadiah (backend dan webhook tetap berjalan pada tema lama).
- Harness uji bertambah ke 40 kelompok perilaku (`tests/v106.php`): migrasi 1→2 dan jalankan ulang, validasi konfigurasi, evaluasi single/total, klaim tamu, kegagalan adapter, paralel, entri manual dan izin tiap peran, serta adapter tema nyata.

## [1.0.5] - 2026-10-31

- Plugin WordPress terpisah dengan migrasi InnoDB skema 1 dan lifecycle nondestruktif.
- Donasi guest/member, intent idempoten, Snap server, resume, retry eksplisit, status privat, verifikasi signature dan GET Status, event finansial dan rekonsiliasi.
- Total/ranking produksi berdasarkan persetujuan publik, periode timezone aplikasi, refund kumulatif, seri dan pemisahan sandbox.
- Verifikasi/pemulihan/claim email sekali pakai, privasi per intent, WordPress privacy tools dan integrasi akun tema.
- Endpoint panel untuk transaksi/timeline/catatan/moderasi, donatur, pengaturan revision, keamanan/IP, audit, CSV dan diagnostik.
- Resolver trusted proxy CIDR IPv4/IPv6, hash/masking, authenticated encryption opsional, reveal diaudit, limiter atomik dan retensi.
- Pengujian terisolasi dan concurrency menggunakan gateway double; tidak menyatakan pembayaran produksi telah diuji. Refund/cancel panel belum aktif tanpa verifikasi dukungan merchant.
