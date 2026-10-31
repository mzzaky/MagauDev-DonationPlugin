=== Magaudev Donations ===
Contributors: mzzaky
Tags: donations, midtrans, qris
Requires at least: 6.6
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 1.0.6
License: GPLv2 or later

Donasi publik guest/member melalui Midtrans Snap dan panel magaudev.

== Description ==
Backend plugin mandiri; halaman, panel, dan hadiah membutuhkan tema magaudev >=1.0.6. Default sandbox dan paused. Hadiah XP/koin hanya ada bila diaktifkan admin dan hanya untuk member pada pembayaran produksi. Donasi tidak pernah memberi akses kelas. Lihat README.md dan docs untuk setup, pengujian dan privasi.

== Installation ==
Unggah ZIP, aktifkan, konfigurasi konstanta server dan pilih kanal merchant di panel. Produksi harus diaktifkan secara eksplisit melalui konfigurasi server dan HTTPS.

== Changelog ==
= 1.0.6 =
Skema 2: hadiah donasi (XP dan koin) yang dapat dikustomisasi, donatur manual (tambah, ubah, batalkan lewat event ledger), endpoint pendukung terbaru, kapabilitas mgdd_manual. Tanpa perubahan pada alur webhook dan rekonsiliasi gateway.

= 1.0.5 =
Rilis awal companion plugin: intent/idempotensi, Snap, webhook terverifikasi, ledger/refund, sesi guest, claim email, top donatur, panel, audit dan IP terenkripsi opsional.
