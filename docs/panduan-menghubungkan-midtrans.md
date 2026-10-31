# Panduan menghubungkan Magaudev Donations ke Midtrans

Untuk plugin **Magaudev Donations 1.0.5** dan tema **magaudev minimal 1.0.5**. Diperiksa terhadap kode plugin dan dokumentasi resmi pada **31 Oktober 2026**.

Mulai dari **Sandbox**. Setelah alur pembayaran dan webhook lulus pengujian, ikuti bagian Production. Panduan ini tidak mengubah konfigurasi situs atau mengaktifkan penerimaan pembayaran.

## 1. Siapkan akun dan situs

1. Daftar atau masuk ke [dashboard Midtrans](https://dashboard.midtrans.com/).
2. Pilih environment **Sandbox** pada dashboard.
3. Pastikan plugin Magaudev Donations aktif dan tema magaudev yang kompatibel terpasang.
4. Pastikan PHP 8.1+ 64 bit, ekstensi sodium dan mbstring tersedia. Server WordPress harus dapat membuat koneksi HTTPS keluar ke Midtrans.
5. Siapkan staging dengan domain HTTPS publik untuk pengujian webhook lengkap.

| Lingkungan WordPress | Yang dapat diuji |
| --- | --- |
| LocalWP, misalnya `http://magaudev.ai` | Membuat checkout sandbox, membuka Snap, simulasi pembayaran, lalu memeriksa hasil melalui tombol **Periksa ulang**. Memerlukan koneksi keluar ke Midtrans. |
| Staging HTTPS publik | Alur di atas ditambah pengiriman webhook Midtrans ke WordPress dan pembaruan otomatis. |
| Situs produksi HTTPS | Pembayaran sungguhan, setelah akun dan kanal merchant disetujui serta pengujian selesai. |

Domain LocalWP yang hanya diarahkan melalui hosts komputer tidak menjadi endpoint publik. Untuk memvalidasi webhook, gunakan staging yang dapat dijangkau server Midtrans. Memasang SSL lokal saja tidak memberikan akses dari internet.

## 2. Ambil tiga nilai dari dashboard Sandbox

Buka **Settings → Access Keys**. Catat **Merchant ID**, **Client Key**, dan **Server Key** dari environment Sandbox. Merchant ID juga dapat ditemukan di profil akun. Gunakan Merchant ID akun Midtrans yang ditampilkan di dashboard. [Rujukan Access Keys](https://docs.midtrans.com/docs/midtrans-account#retrieving-api-access-keys), [lokasi Merchant ID](https://docs.midtrans.com/docs/how-to-check-merchant-id-mid-on-midtrans-administration-portal-map).

| Nilai dashboard | Nama konstanta plugin |
| --- | --- |
| Sandbox Server Key | `MGDD_SANDBOX_SERVER_KEY` |
| Sandbox Client Key | `MGDD_SANDBOX_CLIENT_KEY` |
| Sandbox Merchant ID | `MGDD_SANDBOX_MERCHANT_ID` |

Simpan Server Key pada konfigurasi server. Jangan mengirimkannya lewat chat, menaruhnya di JavaScript, atau memasukkannya ke Git. Plugin membaca nilai ini dari `wp-config.php`; formulir gateway hanya mengatur mode dan pilihan kanal.

## 3. Isi `wp-config.php` WordPress

Cadangkan berkas tersebut, kemudian buka `wp-config.php` pada root instalasi WordPress. Di LocalWP, buka folder situs melalui aplikasi Local, lalu masuk ke `app/public/`.

Tambahkan blok berikut sebelum komentar penutup pengaturan WordPress atau sebelum baris yang memuat `wp-settings.php`. Ganti seluruh placeholder dengan nilai Sandbox akun sendiri:

```php
// Magaudev Donations — Sandbox.
define( 'MGDD_SANDBOX_SERVER_KEY', 'ISI_SERVER_KEY_SANDBOX' );
define( 'MGDD_SANDBOX_CLIENT_KEY', 'ISI_CLIENT_KEY_SANDBOX' );
define( 'MGDD_SANDBOX_MERCHANT_ID', 'ISI_MERCHANT_ID_SANDBOX' );

// Penerimaan production tetap belum diizinkan.
define( 'MGDD_PRODUCTION_ENABLED', false );
```

Jika konstanta sudah ada, ubah nilai pada definisi yang sama. Jangan membuat dua definisi untuk satu nama. Jika menempelkan ke blok PHP yang sudah terbuka, tidak perlu menambahkan `<?php` lagi.

Simpan, lalu muat ulang panel. Nilai di atas adalah contoh penempatan; placeholder tidak dapat digunakan untuk pembayaran.

## 4. Atur URL pada dashboard Midtrans

Gunakan URL staging atau produksi yang benar-benar digunakan WordPress. Contoh berikut memakai `https://staging.example.com`; ganti dengan domain sendiri. Jika WordPress dipasang di subdirektori, salin URL lengkap dari panel plugin agar prefix situs tetap benar.

### Payment Notification URL / webhook

Pada dashboard environment yang sedang diuji, buka **Settings → Configuration** atau halaman **Payment Settings**, lalu isi bagian **Payment Notification URL**:

```text
https://staging.example.com/wp-json/magaudev-donations/v1/webhook
```

Simpan pengaturan. Midtrans mengirim perubahan status melalui HTTP POST ke URL ini. Halaman dashboard **Payment Settings** juga menyediakan riwayat notifikasi; pada detail transaksi, periksa bagian **Notification Log**. [Pengaturan webhook](https://docs.midtrans.com/docs/https-notification-webhooks), [Payment Settings](https://docs.midtrans.com/docs/payment-settings).

Endpoint webhook harus dapat menerima POST tanpa login WordPress, HTTP Basic Auth staging, atau challenge CAPTCHA/WAF. Jika staging dilindungi, buat pengecualian khusus pada path webhook dan pertahankan perlindungan lain. Plugin memvalidasi signature dan mengambil status dari API Midtrans.

Jangan mengisi bagian **BI SNAP Notification URL** untuk integrasi ini. Plugin memakai produk checkout **Midtrans Snap**.

### Redirect setelah pembayaran

Buka **Settings → Snap Preferences → System Settings**. Isi field redirect yang tersedia dengan halaman status plugin:

| Field | URL contoh |
| --- | --- |
| Finish URL | `https://staging.example.com/donasi/status/` |
| Unfinish URL, jika tersedia | `https://staging.example.com/donasi/status/` |
| Error URL | `https://staging.example.com/donasi/status/` |

Klik **Save**. Letak atau nama menu dapat mengikuti tampilan dashboard akun. [Snap Preferences](https://docs.midtrans.com/docs/snap-preference-snap-checkout-settings).

Plugin juga mengirim `callbacks.finish` berdasarkan URL WordPress ketika membuat token Snap. Karena parameter request dapat mengungguli pengaturan dashboard, domain WordPress dan domain konfigurasi Midtrans harus konsisten. [Panduan integrasi Snap](https://docs.midtrans.com/docs/snap-snap-integration-guide).

Satu halaman status digunakan untuk semua hasil: halaman ini membaca status backend. Redirect selesai atau popup ditutup tidak membuat transaksi otomatis berhasil.

## 5. Aktifkan kanal pada panel magaudev

1. Masuk sebagai administrator WordPress atau admin magaudev.
2. Buka panel admin sesuai slug situs, lalu **Donasi → Gateway dan kesehatan**. Slug bawaan panel adalah `/panel/`.
3. Pilih **Mode: Sandbox**.
4. Pilih kanal yang tersedia pada akun merchant: **QRIS**, **GoPay**, dan/atau **ShopeePay**. Untuk pengujian pertama, satu kanal yang telah tersedia sudah cukup.
5. Pertahankan centang **Jeda donasi baru**, lalu klik **Simpan pengaturan**.
6. Periksa indikator kesiapan. Setelah konfigurasi siap, hapus centang **Jeda donasi baru** dan simpan lagi.

Pada **Snap Preferences → Payment Channels** Midtrans, pastikan kanal yang dipilih memang tersedia untuk merchant. Centang kanal di plugin membatasi metode yang diminta, tetapi tidak mengaktifkan layanan merchant yang belum tersedia. [Perilaku Payment Channels](https://docs.midtrans.com/docs/snap-preference-snap-checkout-settings).

Arti indikator pada plugin:

| Indikator | Arti |
| --- | --- |
| `credentials` | Ketiga konstanta untuk environment terpilih terisi. Ini belum membuktikan key diterima Midtrans. |
| `production_allowed` | Sandbox diizinkan; pada Production, HTTPS dan konstanta izin production harus memenuhi syarat. |
| `captcha_ready` | CAPTCHA tidak diwajibkan, atau konfigurasi CAPTCHA tersedia. |
| `ready` | Prasyarat konfigurasi, kanal dan sodium terpenuhi. |
| `accepting` | Konfigurasi siap dan opsi jeda dimatikan. Mode maintenance tema tetap dapat menolak checkout baru. |
| `ip_encryption` | Kunci enkripsi IP opsional siap. Nilai false tidak menghalangi integrasi Midtrans; IP lengkap tidak disimpan. |

Jika tema tidak aktif, **wp-admin → Pengaturan → Magaudev Donations** menyediakan diagnostik dan tombol jeda dasar. Pengaturan gateway lengkap tetap berada pada panel magaudev.

## 6. Uji pembayaran Sandbox

1. Buka `/donasi/` dengan browser sebagai guest.
2. Pastikan label **Mode uji sandbox** terlihat dan tombol pembayaran aktif.
3. Pilih nominal, misalnya **Rp10.000**, isi nama/alias dan email yang dapat diakses. Pilih izin tampil publik sesuai kebutuhan.
4. Tekan **Lanjut ke pembayaran**. Pada halaman status, pilih **Lanjutkan pembayaran** untuk membuka Snap.
5. Pilih kanal yang sedang diuji.
6. Selesaikan simulasi menggunakan alat Sandbox resmi di bawah.
7. Kembali ke halaman status. Tunggu pembaruan atau tekan **Periksa ulang**.
8. Cocokkan transaksi pada dashboard Midtrans Sandbox dan **Panel Donasi → Transaksi**, dengan filter lingkungan Sandbox.

**Gunakan simulator, tanpa uang nyata.** QR atau referensi Sandbox jangan dibayar menggunakan aplikasi bank/dompet digital sungguhan. [Aturan pengujian Sandbox Midtrans](https://docs.midtrans.com/docs/testing-payment-on-sandbox).

| Kanal | Cara simulasi |
| --- | --- |
| QRIS | Salin URL gambar QR dari checkout, lalu masukkan pada [QRIS Simulator resmi](https://simulator.sandbox.midtrans.com/v2/qris/index). |
| GoPay / ShopeePay di desktop | Ikuti petunjuk simulator pada checkout; untuk QR, gunakan URL gambar QR pada QRIS Simulator. |
| GoPay / ShopeePay di mobile | Ikuti perpindahan ke simulator web Sandbox yang diberikan checkout. |

Alur simulasi dapat berbeda menurut konfigurasi Sandbox akun. Gunakan tautan terbaru pada [dokumentasi pengujian Midtrans](https://docs.midtrans.com/docs/testing-payment-on-sandbox).

Hasil yang diharapkan pada plugin:

- Transaksi berstatus **Dukungan berhasil diterima** hanya setelah server mengonfirmasi pembayaran.
- Detail transaksi memperlihatkan percobaan pembayaran dan timeline sumber pembaruan.
- Menutup popup tetap memungkinkan melanjutkan pembayaran yang masih sah.
- Nominal Sandbox tidak masuk total dukungan atau top donatur produksi. Daftar publik tetap kosong jika belum ada pembayaran produksi yang disetujui tampil.

## 7. Buktikan webhook bekerja pada staging

1. Buat pembayaran Sandbox baru dari staging HTTPS publik yang telah dikonfigurasi.
2. Selesaikan pembayaran melalui simulator.
3. Pada detail transaksi Midtrans, periksa **Notification Log** untuk URL webhook staging.
4. Pastikan notifikasi transaksi sebenarnya mendapatkan respons sukses **2xx**.
5. Pada panel plugin, periksa **Webhook berhasil terakhir**, status transaksi dan log jenis **webhook**.
6. Ulangi menggunakan guest tanpa login dan kanal merchant lain yang diaktifkan.

Untuk memastikan webhook yang memperbarui status, setelah menyelesaikan pembayaran tutup halaman status donor sementara. Periksa panel transaksi dari browser admin terpisah sebelum menekan tombol sinkronisasi. Jika perlu, lihat sumber event pada timeline: `webhook`, `status`, atau `reconcile`.

Membuka URL webhook dari address bar mengirim GET, sedangkan endpoint ini menerima POST. Hasil GET yang bukan 200 tidak membuktikan webhook rusak. Payload contoh dengan order asing atau signature tidak sah juga ditolak; validasi integrasi menggunakan transaksi Sandbox milik plugin yang benar-benar dibuat pada merchant terkait.

Jika hanya memakai LocalWP, tombol **Periksa ulang** dapat memperbarui status melalui koneksi keluar ke Midtrans, tetapi itu belum menjadi bukti webhook masuk berhasil.

## 8. Siapkan cron dan email

Plugin menjadwalkan rekonsiliasi **per jam**, maksimal 20 percobaan per batch, dan pengiriman kode email melalui pekerjaan sekali jalan. Agar pekerjaan jatuh tempo berjalan pada situs sepi, atur cron hosting setiap lima menit, misalnya dengan WP-CLI:

```sh
*/5 * * * * cd /path/ke/wordpress && /path/ke/wp cron event run --due-now --quiet
```

Ganti path dengan instalasi hosting sendiri dan gunakan pengguna sistem pemilik situs. Jadwal lima menit memicu pekerjaan yang sudah jatuh tempo; interval rekonsiliasi plugin tetap per jam. Tombol **Donasi → Operasional → Rekonsiliasi batch** tersedia untuk pemeriksaan manual.

Pastikan `wp_mail` dapat mengirim melalui SMTP yang dikonfigurasi pada situs. Di LocalWP, periksa Mailpit melalui menu alat situs. Kode email berlaku 30 menit sejak dibuat untuk dikirim. Respons formulir permintaan kode tetap generik dan tidak menjamin pesan telah masuk ke inbox.

Cron/email membantu pemulihan, verifikasi dan konfirmasi; keberhasilan pembayaran tetap berasal dari verifikasi gateway pada backend.

## 9. Beralih ke Production

Lakukan setelah akun merchant dan kanal Production disetujui, pengujian Sandbox/webhook lulus, dan situs publik memakai HTTPS. Sandbox dan Production menggunakan konfigurasi masing-masing. [Panduan migrasi Production Midtrans](https://docs.midtrans.com/docs/how-do-i-migrate-my-account-from-sandbox-to-production).

1. Aktifkan **Jeda donasi baru** pada panel plugin selama pergantian mode.
2. Pada dashboard Midtrans, pilih environment **Production** dan ambil Merchant ID, Client Key, Server Key untuk Production.
3. Isi URL webhook dan redirect domain produksi pada pengaturan dashboard Production.
4. Tambahkan konstanta Production pada `wp-config.php`:

```php
define( 'MGDD_PRODUCTION_SERVER_KEY', 'ISI_SERVER_KEY_PRODUCTION' );
define( 'MGDD_PRODUCTION_CLIENT_KEY', 'ISI_CLIENT_KEY_PRODUCTION' );
define( 'MGDD_PRODUCTION_MERCHANT_ID', 'ISI_MERCHANT_ID_PRODUCTION' );
```

5. Ubah definisi `MGDD_PRODUCTION_ENABLED` yang sebelumnya false menjadi:

```php
define( 'MGDD_PRODUCTION_ENABLED', true );
```

6. Di **Donasi → Gateway dan kesehatan**, pilih **Mode: Produksi** dan kanal yang benar-benar aktif pada akun Production. Simpan dengan jeda masih aktif.
7. Periksa kesiapan, domain HTTPS, cron dan SMTP. Pertahankan konstanta Sandbox agar pembayaran Sandbox lama masih dapat diperiksa.
8. Saat siap menerima uang sungguhan, matikan jeda dan lakukan transaksi Production terkontrol, misalnya Rp10.000, melalui kanal yang diaktifkan. Pembayaran ini menggunakan dana nyata dan harus dilakukan oleh operator yang berwenang.
9. Cocokkan hasil pada dashboard **Production**, status backend dan Notification Log. Jika donor memilih tampil publik, periksa kontribusinya pada top donatur produksi.

Jangan mengganti Merchant ID untuk menangani transaksi merchant lain. Transaksi tersimpan membawa environment/merchant asal; konfigurasi yang diperlukan untuk memeriksanya harus tetap tersedia. Jika akun atau key berubah, selesaikan rencana penanganan transaksi lama terlebih dahulu.

## 10. Jika terjadi masalah

| Gejala | Pemeriksaan / tindakan |
| --- | --- |
| Tombol pembayaran nonaktif | Periksa tiga konstanta environment terpilih, kanal, sodium, opsi jeda, CAPTCHA jika diwajibkan, dan mode maintenance tema. |
| `credentials=true` tetapi checkout belum berhasil | Indikator hanya memeriksa nilai terisi. Cocokkan ulang Server Key/Client Key/Merchant ID dan mode dashboard; periksa koneksi HTTPS keluar server. |
| Snap gagal dimuat | Periksa Client Key, koneksi browser, ekstensi pemblokir dan Console/Network. Gunakan tombol fallback **Buka halaman Midtrans** milik pembayaran yang sama jika tersedia. |
| Kanal tidak muncul | Pastikan tersedia pada akun merchant dan Snap Preferences, lalu cocokkan pilihan kanal plugin. |
| `unknown` / menunggu kepastian gateway | Periksa order terkait pada dashboard dan sinkronisasi status. Pertahankan referensi yang sama; jangan otomatis membuat pembayaran pengganti. Timeout belum membuktikan order tidak terbentuk. |
| Status belum ditemukan setelah sesi Snap dibuat | Metode mungkin belum dipilih. Lanjutkan sesi yang sah dan periksa ulang; jangan langsung menyimpulkan gagal. |
| Simulator selesai, situs masih pending | Periksa lingkungan laporan, Notification Log, keterjangkauan webhook dan tombol **Periksa ulang**. Pada LocalWP, uji webhook masuk memerlukan endpoint publik. |
| Webhook 401/403 | Periksa proteksi hosting/WAF dan kecocokan merchant/environment/key. Signature tidak sah tetap harus ditolak. |
| Webhook 404 | Cocokkan domain, subdirektori, path REST dan status aktif plugin. Pastikan POST ke URL yang ditampilkan panel. |
| Webhook 503 | Periksa akses API Midtrans, database dan log tersaring. Plugin dapat menolak ACK sukses sampai verifikasi/penyimpanan berhasil; gunakan retry notifikasi transaksi sah dan rekonsiliasi setelah masalah selesai. |
| Belum ada nilai Webhook berhasil terakhir | Belum ada webhook sah yang berhasil diproses. Membuka Snap atau sinkronisasi manual tidak mengisi indikator ini. |
| Email kode tidak diterima | Periksa cron, SMTP/Mailpit, spam, dan pembatasan permintaan; kode dikirim melalui pekerjaan terjadwal. |
| Top donatur tetap kosong setelah tes | Data Sandbox tidak masuk ranking produksi; pembayaran produksi juga harus berhasil dan memiliki persetujuan nama publik. |

Untuk menjeda penerimaan normal, gunakan opsi **Jeda donasi baru**. Pertahankan plugin aktif agar webhook pembayaran lama tetap diproses. Refund/cancel melalui panel belum aktif pada v1.0.5; tindakan merchant yang didukung dilakukan melalui dashboard sesuai prosedur operator, kemudian statusnya disinkronkan.

## Checklist sebelum menerima donasi produksi

- [ ] Plugin/tema kompatibel dan tersedia backup.
- [ ] Akun serta kanal Production disetujui merchant.
- [ ] Kredensial environment yang tepat terpasang pada server.
- [ ] Webhook HTTPS publik untuk transaksi sebenarnya terbukti sukses.
- [ ] Redirect mengarah ke `/donasi/status/` pada situs yang benar.
- [ ] Guest dapat membuat pembayaran tanpa login.
- [ ] Pembayaran pending dapat dilanjutkan; status berhasil berasal dari server.
- [ ] Cron, SMTP dan pemeriksaan operasional tersedia.
- [ ] Transaksi Production terkontrol telah dicocokkan dengan dashboard.
- [ ] Pilihan privasi dan total produksi sesuai harapan.

Detail teknis: [setup Midtrans](midtrans-setup.md), [API](api.md), [keamanan dan privasi](security-and-privacy.md).
