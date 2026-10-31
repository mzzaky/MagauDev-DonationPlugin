# Dev log 1.0.6 — Hadiah dan donatur manual

## 31 Oktober 2026 — Audit dan lokasi repo

Repo sumber plugin ditemukan di `D:\Projek\MagauDevDonationPluginWP` (branch `codex/v1.0.5-donasi`, HEAD `6d02b01`, bersih; `src/` identik dengan salinan terpasang di LocalWP). Pemilik `.git` adalah akun sandbox lain sehingga git hanya dipakai dengan `-c safe.directory=…` per perintah, tanpa mengubah konfigurasi. PHP `win32` pada LocalWP ternyata 32-bit (plugin butuh 64-bit); harness dijalankan dengan `win64`. Baseline harness: 26 kelompok, 275 asersi lolos.

## Keputusan

- Kolom penanda donatur manual bernama `is_manual`: `manual` ditolak MySQL 8.4 (kata cadangan), ditemukan saat migrasi pertama gagal.
- Hadiah tipe *satu donasi* hanya untuk donasi sejak fitur diaktifkan (`rewards.since`), agar mengaktifkan fitur tidak memberi hadiah retroaktif; tipe *akumulasi* menghitung seluruh dukungan bersih yang layak (didokumentasikan). Entri manual bertanda hadiah mengabaikan `since` karena admin memilihnya secara eksplisit.
- Keunikan ganda pada tabel `rewards`: `(attempt_id, tier_id)` dan `(tier_id, owner_key)`. Kunci kedua menjamin hadiah akumulasi sekali per member; saat klaim, baris tamu dipindahkan ke `u<user>` atau dilewati bila sudah ada.
- `Payments::apply()` mengevaluasi hadiah pada setiap keluaran sukses (termasuk duplikat), sehingga rekonsiliasi/webhook ulang memperbaiki evaluasi yang gagal; idempoten karena kunci unik.
- Config: `rewards` hanya divalidasi bila ikut dikirim, supaya penyimpanan lain (mis. tombol jeda) tidak gagal akibat jenis hadiah yang hilang.

## Hasil pengujian

- `php tests/run.php` (DB/opsi `mgddt_`, gateway double, proses paralel): **40 kelompok perilaku, 634 asersi** lolos, termasuk migrasi 1→2 dua kali dengan data lama, FAQ, validasi hadiah, single/total, sandbox, klaim tamu, kegagalan adapter, paralel, entri manual (validasi, atestasi, idempotensi, revisi 409, void, `rebuild()` identik, bukan sinkronisasi/kwitansi), izin tiap peran pada endpoint baru, tidak ada kebocoran `manual`/email pada payload publik, privasi, dan satu tes dengan adapter tema nyata (ledger tema `mgdt_`).
- PHPCS WordPress-Extra + PHPCompatibilityWP bersih (memakai `vendor/bin/phpcs` milik tema).
- Tidak diverifikasi: sandbox merchant nyata, webhook HTTPS publik, produksi.
