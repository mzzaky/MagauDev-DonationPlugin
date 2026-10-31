# Setup Midtrans Snap

Untuk petunjuk dashboard dan konfigurasi dari awal, ikuti [panduan menghubungkan Midtrans](panduan-menghubungkan-midtrans.md). Dokumen ini menjadi referensi teknis adapter/plugin.

Ini **Snap checkout**, bukan protokol BI-SNAP. Dokumentasi resmi diperiksa 31 Oktober 2026:

- [Snap Integration Guide](https://docs.midtrans.com/docs/snap-snap-integration-guide)
- [JSON objects / order ID](https://docs.midtrans.com/reference/json-objects)
- [Webhook dan signature](https://docs.midtrans.com/docs/https-notification-webhooks)
- [Transaction Status Cycle](https://docs.midtrans.com/docs/transaction-status-cycle)
- [GET Status](https://docs.midtrans.com/reference/get-transaction-status)
- [Batas nominal kanal](https://docs.midtrans.com/docs/berapa-jumlah-transaksi-minimal-dan-maksimal-yang-dapat-diterima-oleh-midtrans)

Konfigurasi di `wp-config.php`, gunakan nilai dari dashboard merchant sendiri. Jangan menyalin placeholder sebagai kredensial:

```php
define( 'MGDD_SANDBOX_SERVER_KEY', '<sandbox-server-key>' );
define( 'MGDD_SANDBOX_CLIENT_KEY', '<sandbox-client-key>' );
define( 'MGDD_SANDBOX_MERCHANT_ID', '<merchant-id>' );
// Untuk produksi, isi trio MGDD_PRODUCTION_SERVER_KEY / CLIENT_KEY / MERCHANT_ID.
// MGDD_PRODUCTION_ENABLED harus true hanya setelah otorisasi operasional dan validasi HTTPS.
```

Key dipilih menurut environment attempt, sehingga pergantian mode tidak merusak pembayaran lama. Pertahankan key merchant lama sampai pembayaran/refund selesai. Tidak ada formulir yang menampilkan server key. Client key hanya diberikan pada pemilik checkout yang sah.

Pilih QRIS/GoPay/ShopeePay di panel hanya setelah channel diaktifkan merchant. Default aplikasi minimum Rp10.000, maksimum Rp2.000.000; batas keras Rp10.000.000 mengikuti kanal QRIS awal. Ini kebijakan produk, bukan minimum universal Midtrans. Batas issuer/verifikasi wallet/merchant dapat lebih kecil.

Webhook: `https://<host>/wp-json/magaudev-donations/v1/webhook`. Callback: `https://<host>/donasi/status/`. Callback/browser tidak mengubah ledger. Signature menggunakan urutan order_id + status_code + gross_amount + server key, lalu SHA512. Payload diperiksa dan GET Status diambil dengan key server; ACK hanya setelah transaksi DB berhasil. Kegagalan sementara merespons non-2xx untuk retry Midtrans. Metode awal menganggap settlement terverifikasi sebagai paid; capture/authorize masuk review.

HTTP API hanya menuju host resmi, TLS aktif dan redirect HTTP dimatikan. Redirect Snap fallback dibatasi HTTPS host app Midtrans dan path `/snap/`. CSP tema menambahkan origin spesifik hanya pada halaman checkout/status.

404 GET Status setelah token dibuat belum berarti gagal karena metode mungkin belum dipilih. Timeout create → unknown, bukan order pengganti. Pengguna dapat melanjutkan token yang sudah tersedia. Jika create timeout tanpa token dan status tetap 404, pengelola perlu memastikan keadaan order pada dashboard/support Midtrans; plugin tidak menganggapnya aman untuk pembayaran pengganti otomatis.

Uji nyata memerlukan key sandbox dan URL HTTPS publik yang diizinkan; localhost tidak menerima webhook eksternal. Tidak membuka tunnel, mengganti URL situs atau mengaktifkan produksi sebagai bagian implementasi. Uji dengan mock dipisahkan dari sandbox nyata dan produksi pada laporan.

WP-Cron menjalankan batch per jam (maksimal 20), retry pending dengan backoff hingga 6 jam dan batas 120 pemeriksaan; pembayaran berhasil diperiksa harian selama jendela operasional 180 hari, webhook tetap menjadi jalur utama refund di luar jendela. Tinjau antrean needs_review dan lakukan sync admin/manual bila batas retry tercapai. Produksi: jalankan cron server setiap 5 menit memakai WP-CLI `wp cron event run --due-now`; pantau last_cron/last_webhook dan kegagalan gateway. Jangan menonaktifkan plugin untuk jeda operasional.

Kode verifikasi/pemulihan dikirim lewat pekerjaan WP-Cron sekali jalan setelah request; masa berlaku dimulai saat kode dibuat untuk dikirim. Konfirmasi donasi terverifikasi dikirim pada batch operasional, tanpa token atau data privat pada URL. Pengiriman berhasil dicatat agar batch berikut tidak mengirim ulang; kegagalan `wp_mail` dapat dicoba pada batch selanjutnya. Gangguan setelah SMTP menerima email namun sebelum audit tersimpan dapat menyebabkan konfirmasi email duplikat, tanpa menggandakan ledger. Siapkan SMTP dan cron untuk pengiriman tepat waktu; respons formulir generik tidak menjamin mailbox sudah menerima email.

Refund/reversal dari dashboard gateway memperbarui jumlah kumulatif dan periode pembayaran asal. Fitur meminta refund/cancel panel tidak aktif sampai metode dan izin akun merchant terverifikasi; tidak ada refund uang nyata dalam tes.
