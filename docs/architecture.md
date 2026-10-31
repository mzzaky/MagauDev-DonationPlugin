# Arsitektur Donasi 1.0.6

Tanggal: 31 Oktober 2026. Plugin PHP 8.1+/WordPress 6.6+, tema magaudev >=1.0.5. Sumber plugin terpisah dari tema, tanpa Composer runtime. Skema plugin 2 (sejak 1.0.6); skema tema tidak berubah.

## Kontrak yang ditetapkan sebelum implementasi

- Plugin memiliki data, konfigurasi, checkout, webhook, rekonsiliasi, identitas, privasi, audit dan keamanan. Tema memiliki template, CSS/TS, navigasi dan panel Preact.
- Plugin dimuat WordPress secara normal. `magaudev_booted` memasang route `donation`, `donation_status`, `donors`. API plugin `/magaudev-donations/v1` tidak mengganti API tema.
- Hook baru tema `magaudev_panel_menu` menambahkan menu sesuai capability. Adapter tema memeriksa versi plugin sebelum memanggil API. Tidak ada require lintas repo pada runtime.
- Boot publik hanya URL API, versi dan readiness tersaring. Email/IP/server key/Snap token tidak masuk boot. Cookie sesi HttpOnly/SameSite=Lax, 256 bit, hash di DB. CSRF terikat sesi dan origin wajib sama untuk mutasi browser. Member juga memakai nonce REST WordPress.
- Semua konfigurasi rahasia berupa konstanta server per environment. Default sandbox, paused. Produksi memerlukan HTTPS dan persetujuan konfigurasi server `MGDD_PRODUCTION_ENABLED`.

## Data dan konsistensi

Tabel InnoDB `{wp_prefix}mgdd_{donors,intents,attempts,events,audit,sessions,tokens,limits,blocks}`. Namespace tes `mgddt_` dan opsi `mgddt_` terpisah, tidak menghapus tabel tema/akun pengguna. Nominal integer rupiah; default 10.000–2.000.000, batas keras 10.000.000 untuk kanal awal QRIS/GoPay/ShopeePay. Batas issuer tetap berlaku.

Intent unik `(scope_hash,idempotency_hash)`, payload hash mendeteksi konflik. Reservasi transaksi DB pendek lalu HTTP di luar transaksi. Attempt `creating`/`unknown` tidak otomatis membuat order pengganti; status 404 sebelum pemilihan metode tetap belum diketahui. Resume menggunakan token sama. Retry terminal harus eksplisit. Order unik environment/merchant; transaction ID unik environment/merchant. Dua pembayaran berbeda tetap tersimpan dan diberi tanda review.

Webhook memvalidasi signature SHA512 lalu membaca GET Status dengan key environment attempt, memeriksa order/merchant/currency/nominal/transaction ID. Lock attempt sebelum perubahan; apply dan rebuild memakai named lock ledger yang sama agar rebuild tidak menimpa ringkasan dari event yang baru berkomit. Lock finansial hanya selama perubahan DB, bukan selama HTTP gateway. Event durable memiliki delta bruto/refund; duplikat tidak menulis delta kedua. Ringkasan dapat dibangun ulang dari ledger. Status browser bukan sumber finansial. Paid tidak turun ke pending; refund/reversal terverifikasi dapat memperbaiki dana. Refund kumulatif memakai nilai terverifikasi, bukan menambahkan setiap notifikasi. Tidak ada refund uang nyata otomatis atau tombol paid manual.

## Identitas dan publikasi

Guest provisional terikat sesi; email sama tidak menggabungkan identitas. Kode email acak sekali pakai berlaku 30 menit, hanya hash disimpan. Kode ditempel di form, tidak ditaruh URL. Claim ke member memerlukan kode mailbox dan sesi login sah. Pilihan publik per intent dipertahankan saat penggabungan. Publikasi pesan terpisah dan perlu moderasi. Penghapusan akun menyembunyikan intent dan memutus identitas tanpa menghapus ledger.

Ranking SQL hanya production, publik, paid dikurangi refund, periode berdasarkan paid_at UTC dengan batas bulan timezone aplikasi (filter adapter tema). Rank nominal sama memakai competition ranking; donor ID untuk urutan stabil. Guest provisional dihitung profil, bukan manusia unik. Tidak ada XP/koin/akses kelas. Tidak memakai cache identitas publik: perubahan privasi berlaku langsung.

## Matriks izin

`mgdd_view`, `mgdd_manage`, `mgdd_settings`, `mgdd_sensitive`, `mgdd_export`, `mgdd_security`, `mgdd_refund`: administrator dan mgd_admin saja; grant idempoten. REST admin memeriksa nonce dan capability sebelum query. Reveal IP memerlukan mgdd_sensitive, alasan dan audit. Ekspor mgdd_export, dibatasi, escaped CSV. Operasi finansial refund belum diaktifkan tanpa kapabilitas merchant yang terverifikasi.

## IP, retensi dan operasional

Peer IPv4/IPv6 sebagai default. XFF diproses kanan ke kiri hanya melalui CIDR trusted proxy. Hash keyed untuk limiter atomik; masked pada log. IP lengkap hanya sodium secretbox dengan kunci khusus dan ID versi di server. Tanpa kunci tidak ada plaintext. Retensi IP default 30 hari (1–90), log operasional 90 (30–365), ledger/audit penting dipertahankan. Blok IP/CIDR sementara hanya browser publik, tidak webhook/admin recovery.

WP-Cron batch bounded dengan lease, retry/backoff; webhook tetap hidup saat paused/maintenance. Nonaktif plugin menghentikan webhook/cron: aktifkan lalu reconcile. Deaktivasi tidak menghapus data. Uninstall tidak menghapus apa pun secara default.

## Acceptance gates

Aktivasi/migrasi ulang aman; money/state/idempotency/signature/IDOR/CSRF/izin/identitas/proxy/enkripsi/refund/ranking diuji dengan gateway double dan DB terisolasi. Tema typecheck/lint/test/contrast/build/release/zip. Browser 360/768/1280 Magma/Krem dan reduced motion. Sandbox asli/webhook HTTPS menunggu kredensial dan endpoint publik; produksi tidak diuji. ZIP terpisah, commit dan push branch terpisah setelah pemeriksaan.

## Skema 2 dan hadiah (1.0.6)

Migrasi idempoten (`dbDelta`) menambah `attempts.source` (`gateway|manual`), `voided_at`, `reward` (layak hadiah) dan `revision`; `donors.is_manual` (kolom `manual` ditolak MySQL 8.4 karena kata cadangan); `intents.note`; serta tabel `rewards`. Kapabilitas `mgdd_manual` diberikan ke `administrator` dan `mgd_admin`.

**Hadiah.** `Payments::apply()` memanggil `Rewards::evaluate_safe()` setelah transaksi dan kunci ledger dilepas; kegagalan hanya menghasilkan audit `reward.error`. Evaluasi hanya untuk `environment='production'`, bruto dikurangi refund > 0, bukan entri void, dan entri manual hanya bila bertanda hadiah dan ada member tertaut. `single` memberi tingkat tertinggi yang terpenuhi (hanya donasi sejak fitur diaktifkan); `total` memberi tiap ambang yang baru terlewati sekali per pemilik (`owner_key`: `a<attempt>`, `u<user>` atau `d<donor>`). Donor member menghasilkan baris `pending` lalu diserahkan ke `mgdd_reward_grant` di bawah kunci per hadiah; tamu menghasilkan `pending_claim` yang dipindahkan ke profil member saat klaim riwayat (`Identity::consume`). Refund sesudah hadiah dicatat sebagai peringatan; ledger tidak menarik hadiah. Rekonsiliasi mencoba ulang `pending|failed` (maks. 8 percobaan, backoff).

**Donatur manual.** Disimpan pada `attempts` dengan `merchant='manual'`, `order_id='manual-<acak>'`, `transaction_id NULL`, `status='paid'`, `sync_count=120`. Perubahan nominal selalu event penyesuaian `gross_delta` (bertanda), void menambah event pembalik sehingga `Payments::rebuild()` menghasilkan angka identik. Jalur gateway (`sync`, rekonsiliasi, kwitansi otomatis, `apply`) menolak atau melewati `source='manual'`.
