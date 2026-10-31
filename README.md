# Magaudev Donations 1.0.5

Companion plugin WordPress untuk donasi publik magaudev melalui **Midtrans Snap**. Guest tidak perlu akun. Donasi tidak memberi XP, koin, level atau akses kelas. Backend, webhook dan ledger tidak bergantung pada tema.

Persyaratan: WordPress 6.6+, PHP 8.1+ **64 bit**, sodium, mbstring, MariaDB 10.6+/MySQL 8, HTTPS untuk produksi. Pasangan presentasi: tema magaudev >=1.0.5. Hosting tidak memerlukan Node/Composer.

## Instalasi

1. Cadangkan database. Unggah `build/magaudev-donations-1.0.5.zip` melalui Plugin → Tambah → Unggah, lalu aktifkan.
2. Pasang tema magaudev 1.0.5. Plugin membuat tabel `mgdd_*` dengan prefix WordPress. Tidak mengubah skema tema.
3. Konfigurasi konstanta server sesuai [panduan Midtrans](docs/midtrans-setup.md). Default sandbox dan dijeda. Tidak ada kunci di repo, opsi umum tema atau formulir admin.
4. Panel magaudev → Donasi → Gateway dan kesehatan: pilih kanal yang benar-benar aktif, periksa kesiapan, lalu buka penerimaan sandbox. Pengaturan → Magaudev Donations di wp-admin menyediakan diagnostik dan tombol jeda darurat ketika tema berganti.
5. URL: `/donasi/`, `/donasi/status/`, `/top-donatur/`. Status menggunakan sesi atau akun, bukan order ID pada URL. Kode email ditempel pada form dan tidak diletakkan pada URL.

## Pengembangan dan pengujian

```powershell
# PHP dan Node >=22 harus tersedia pada PATH; gunakan php.ini LocalWP yang sesuai.
$env:MGD_WP_ROOT = 'path-ke-wordpress-uji'
npm test
# WPCS melalui composer install, atau MGDD_PHPCS ke instalasi pengembangan yang tersedia.
npm run lint
npm run build
npm run package
```

Tes memakai tabel `mgddt_*` dan opsi `mgddt_*`, gateway double dengan jaringan eksternal ditolak, dan proses PHP paralel. Tes membuat/membersihkan hanya akun fixture miliknya. Jangan menjalankan dua suite plugin sekaligus pada namespace uji yang sama. Namespace ini khusus tes, jangan arahkan ke data transaksi nyata.

Dokumentasi: [arsitektur](docs/architecture.md), [API](docs/api.md), [keamanan/privasi](docs/security-and-privacy.md), [dev log](docs/dev-log-v1.0.5.md), [rilis](docs/releases/v1.0.5.md).

## Operasional

Gunakan **jeda** untuk menghentikan donasi baru sambil tetap menerima pembayaran lama. Deaktivasi menghentikan endpoint webhook dan cron; aktifkan kembali, periksa dashboard Midtrans dan rekonsiliasi. Data finansial tetap ada saat deaktivasi maupun uninstall. Tidak ada penghapusan data otomatis; retensi finansial dan penghapusan manual harus diputuskan operator setelah backup dan tinjauan kewajiban operasional.

Refund/cancel dari panel belum diaktifkan karena dukungan kanal/akun belum diverifikasi; lakukan lewat dashboard merchant sesuai prosedur operasional, lalu sinkronkan. Plugin mencatat status refund/reversal terverifikasi, bukan menganggap permintaan refund sebagai uang yang sudah kembali. Biaya gateway dan dana bersih tidak diklaim sebagai angka aktual tanpa sumber.

Belum ada klaim pengujian pembayaran produksi. Kredensial sandbox dan endpoint webhook HTTPS publik diperlukan untuk validasi gateway nyata.
