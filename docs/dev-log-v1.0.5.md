# Dev log 1.0.5 — Donasi

## 31 Oktober 2026 — Fase A

Workspace plugin `D:\Projek\MagauDevDonationPluginWP` awal kosong, Git diinisialisasi pada branch `codex/v1.0.5-donasi`. Remote diberikan pengguna: https://github.com/mzzaky/MagauDev-DonationPlugin.git. Tema awal main 1.0.4, origin https://github.com/mzzaky/MagauDev.git; untracked `.claude/`, `data_kelas/`, dan prompt implementasi dilindungi. Tidak ditemukan AGENTS.md di repo/induk yang diperiksa.

Audit: Router menyediakan filter route/controller, boot melalui magaudev_booted; panel menu butuh filter kecil, frontend memakai base API terpisah. Settings tema tidak boleh menyimpan kunci gateway. IP tema trust boolean tidak digunakan plugin. Runtime PHP LocalWP dan Node repo tema tersedia, perintah PATH global belum terpasang. Baseline sedang dijalankan sebelum perubahan. Dokumen arsitektur/API menetapkan kontrak dan gate fase berikut.

Sumber resmi diperiksa 31 Oktober 2026: Snap Integration Guide, HTTP(S) Notification/Webhooks, Transaction Status Cycle, GET Status, JSON Objects, batas nominal Midtrans. Default nominal produk 10.000–2.000.000 (bukan batas minimum gateway); batas keras 10.000.000, issuer dapat lebih ketat. Snap 404 sebelum metode dipilih bukan pembayaran gagal.

## 31 Oktober 2026 — Fase B–F: implementasi dan validasi

Bootstrap/autoload/lifecycle, Schema/Db, Config/Money/Crypto/Ip/Audit/Limiter/Session, Identity/Privacy, Donations/Gateway/Payments/Jobs, Rest/Admin selesai sebagai modul terpisah. WordPress HTTP API dipakai tanpa SDK runtime. Tidak menaruh request HTTP gateway dalam transaksi DB. Named lock ledger melindungi apply/rebuild, row lock dan unique constraints melindungi event. Tidak ada edit paid manual atau refund otomatis. Kode email lewat WP-Cron untuk menghindari request recovery menunggu SMTP berdasarkan keberadaan mailbox; receipt terverifikasi dikirim lewat batch, tanpa mengubah ledger saat mail gagal.

Pengaturan revision/allowlist, kemampuan per peran, audit tersaring, enkripsi IP optional/versioned, reveal diaudit, retensi, blok sementara, CSV transaksi/log, profil/riwayat/totals per environment dan pesan publik bermoderasi selesai. Guest email sama tetap terpisah; claim memerlukan kode mailbox dan sesi akun. Ekspor akun berdasarkan user ID, bukan kecocokan email. Cache DTO identitas publik tidak digunakan agar perubahan privasi berlaku langsung.

Perbaikan dari tes: header Origin/CSRF kosong diperlakukan sebagai penolakan aman, bukan TypeError; opsi numerik WordPress dibandingkan dengan casting pada tes; bentuk source di repo dipaksa pada bootstrap tes walau ZIP plugin aktif di situs, agar tidak menguji salinan instalasi yang tertinggal. SMTP test dicegat, jaringan gateway test memakai double, token/cookie fixture tidak dicetak atau di-commit.

Hasil akhir pada PHP 8.2.29/WordPress LocalWP/Asia Makassar:

- `php tests/run.php`: **26 kelompok perilaku, 275 asersi**, termasuk 4 proses checkout/limiter/payment paralel, signature palsu, amount/merchant/currency/transaction mismatch, settlement sebelum pending, partial/full refund/reversal berulang, dua receipt nyata, timeout/404, guest/member claim, expiry/anti-enumerasi, CSV injection, IP/CIDR/enkripsi/tamper/missing key/reveal/retensi, capability seluruh endpoint admin, queue kode dan receipt batch, serta rebuild idempoten. Namespace mgddt_ dan opsi mgddt_ dibersihkan; akun dan cron fixture milik tes dibersihkan.
- `node bin/lint.mjs` dengan MGDD_PHPCS menunjuk toolchain pengembangan: syntax PHP dan WordPress-Extra/PHPCompatibilityWP lolos tanpa pelanggaran. `phpcbf` hanya memperbaiki format sebelum pemeriksaan akhir.
- `node bin/check-release.mjs` dan `node bin/package.mjs`: versi 1.0.5 konsisten, ZIP root magaudev-donations/, 32 berkas runtime/docs terpilih; dev log yang mengandung path mesin dikecualikan dari paket.
- ZIP dipasang dan diaktifkan lewat WP-CLI pada LocalWP. Default sandbox, paused, channel kosong, tanpa kredensial nyata. Uji WP-CLI skip-themes: backend/webhook boot tanpa tema. Skip-plugins companion: tema menampilkan unavailable dan menyembunyikan menu.
- Repo tema: **217 tes PHP / 1.541 asersi**, **10 tes player**, typecheck/lint, **44 pasangan kontras**, build/release/ZIP. Browser terpilih **25 tes** public/status/Snap mock/editor/smoke + **1 tes panel nyata**: semua tab, conflict revision, 360/768/1280 Magma/Krem, axe, keyboard/reduced motion, fallback script, state server dan reload. 24 screenshot aman pada docs/releases/v1.0.5 repo tema.

Tidak memakai fixture browser lama yang menghapus tabel notifikasi pengguna secara global. Tes panel memakai satu akun fixture terpisah dan dibersihkan; tidak mereset DB LocalWP. Efek samping berkas seed dari suite PHP tema dipulihkan hanya pada dua file yang bersih di audit awal.

## Fase G — penutupan lokal dan Git

Dokumentasi kedua repo, changelog, versi, aset dan paket terpisah disiapkan. Tema baseline commit f8905f7 sama dengan origin/main saat fetch; plugin remote kosong saat diverifikasi. Branch kedua repo codex/v1.0.5-donasi. Stage eksplisit hanya hasil tugas; .claude/, data_kelas/ dan prompt implementasi untracked tema tidak ikut. Commit/push dilakukan terpisah, tanpa force/merge/tag/deployment. SHA dan verifikasi remote dilaporkan pada penutupan chat; tidak mengarang hash dalam dokumen sebelum commit.

Konfigurasi eksternal masih diperlukan: key/server/client/merchant sandbox, aktivasi channel, SMTP/cron, key IP opsional dan endpoint webhook HTTPS publik yang diizinkan. Tidak membuka tunnel atau mengubah URL situs. Gateway nyata sandbox/webhook eksternal dan produksi **belum diuji**. Refund/cancel panel tetap nonaktif sebelum dukungan akun/channel terverifikasi. Penerimaan produksi tidak diaktifkan. Retensi keuangan merupakan keputusan operasional, bukan klaim kepatuhan otomatis.

## 31 Oktober 2026 — panduan koneksi Midtrans untuk operator

Menambahkan docs/panduan-menghubungkan-midtrans.md atas permintaan pengguna: alur Sandbox dari Access Keys hingga konfigurasi wp-config/panel, URL notification dan Snap Preferences, perbedaan LocalWP/staging HTTPS, simulator QRIS/GoPay/ShopeePay, bukti webhook transaksi sebenarnya, cron/email, Production dan troubleshooting. Panduan membedakan indikator key terisi dari keberhasilan autentikasi gateway, serta menjelaskan bahwa QR Sandbox harus dibayar melalui simulator.

Referensi resmi Midtrans diperiksa kembali: Account Overview/Access Keys, Merchant ID, Payment Settings, Snap Preferences, Integration Guide, Testing Payment on Sandbox, dan migrasi Production. Nama konstanta dan perilaku dicocokkan dengan Config/Gateway/Plugin/Jobs/Rest pada repo. Semua contoh hanya placeholder. Tidak mengubah wp-config, mode/channel/jeda, key merchant atau konfigurasi situs.

Validasi dokumentasi: tautan berkas lokal, nama konstanta dan portabilitas path lolos; git diff --check serta check-release lolos. Paket dibangun ulang menjadi 33 berkas dengan panduan baru. Perubahan hanya dokumentasi/tautan README, sehingga suite runtime tidak diulang. Commit/push dokumentasi pada branch codex/v1.0.5-donasi mengikuti penutupan Git tugas ini.
