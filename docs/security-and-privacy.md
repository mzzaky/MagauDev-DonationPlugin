# Keamanan, privasi dan retensi

Guest cookie 256 bit, HttpOnly, SameSite=Lax, Secure di HTTPS, berlaku 30 hari; hash saja di database. CSRF per sesi dan origin sama diperlukan untuk mutasi browser. WordPress REST nonce tetap memvalidasi member/admin. Order/reference/email/IP bukan bukti kepemilikan. Status milik member hanya dapat dibaca member terkait; guest harus memiliki sesi donor. Cache privat/no-store dan no-referrer pada status/API; tidak ada token pada URL.

Email sama antar guest tidak menggabungkan profil. Kode mailbox acak 256 bit berlaku 30 menit dan sekali pakai, hash di DB, respons permintaan generik dan dibatasi per sesi/IP/email hash. Permintaan kode dijadwalkan melalui WP-Cron, termasuk satu pekerjaan kosong untuk alamat yang tidak ditemukan, sehingga request publik tidak menunggu SMTP berdasarkan keberadaan mailbox. Recover mengirim kode untuk setiap profil provisional yang sesuai (maksimal 20 per permintaan); claim memerlukan login sah dan kode. Penggabungan memakai transaksi DB, menjaga persetujuan per intent. Ekspor akun tema memakai user ID yang sudah dikaitkan, bukan kecocokan email otomatis. Privacy tools WordPress memakai proses verifikasi mailbox WordPress.

Serangan yang ditangani: CSRF/IDOR, pengambilalihan email tanpa proof, manipulasi nominal/status, replay/concurrency, refund ganda, spoofed proxy, XSS, SQL injection, CSV formula injection, SSRF/open redirect, kebocoran key. Semua nominal integer IDR, query nilai prepared, teks publik di-escape/textContent. Snap token terenkripsi dengan sodium memakai derivasi salt rahasia WordPress; penggantian salt menghilangkan kemampuan resume token lama, tetapi rekonsiliasi gateway tetap tersedia.

## Kunci IP

IP audit dibedakan menurut kind visitor/admin/webhook/payment; IP pengirim webhook bukan donor. Default peer address; XFF hanya diproses kanan ke kiri melalui CIDR trusted proxy. Hash keyed untuk limiter/pencarian, masking untuk log. Tidak ada geolokasi pihak ketiga.

```php
// Buat key acak 32 byte di lingkungan aman, simpan base64 di secret manager/config server.
define( 'MGDD_IP_KEY_ID', 'v1' );
define( 'MGDD_IP_KEYS', [ 'v1' => '<base64-32-random-bytes>' ] );
```

Tanpa key valid, hanya hash/masked tersimpan dan diagnostik menyatakan reveal belum siap. Sodium authenticated encryption memverifikasi integritas. Rotasi: tambahkan key baru, ubah active ID, pertahankan key lama selama retensi/backup terkait masih diperlukan; kehilangan key membuat ciphertext lama tidak dapat dibuka. Reveal memerlukan mgdd_sensitive, nonce dan alasan minimal 8 karakter, dicatat sebelum dekripsi; UI tidak menaruh IP di URL.

IP lengkap default 30 hari, dapat 1–90. Log operasional/security default 90 hari, dapat 30–365. Cleanup batch bounded; audit penting/ledger tidak dihapus melalui pembersihan log. Retensi keuangan belum ditentukan sebagai kewajiban hukum otomatis: operator harus memutuskan bersama kebijakan organisasi dan kebutuhan pencatatan. Backup tetap dapat menyimpan ciphertext/hash lebih lama; sesuaikan siklus backup, kontrol akses dan key lifecycle. Hash/mask tetap dapat menjadi data terkait individu dan perlu perlindungan akses.

Audit append-only pada lapisan aplikasi, bukan kebal pemilik database. Payload gateway mentah, Authorization, cookie, signature/token tidak disimpan pada audit. Catatan menyaring key yang dikonfigurasi, pola rahasia dan email; operator tetap harus menghindari menulis data sensitif pada catatan. Reveal/ekspor/perubahan pengaturan/moderasi/claim diaudit. CSV default tanpa email/IP/token, maksimal 1000 baris/93 hari dan 3 ekspor/jam; formula spreadsheet diberi prefix aman.

Blokir CIDR bersifat sementara maksimal 168 jam dan hanya checkout; webhook dan pemulihan admin tidak mengikuti blok. Limiter atomik database berdiri sendiri dari bucket login/kelas. Kegagalan DB tidak dianggap keberhasilan finansial. Tidak ada refund otomatis atau edit status paid manual.

Saat insiden: jeda donasi baru, pertahankan webhook, batasi akses operator, periksa timeline dan dashboard gateway, rotasi key yang relevan, sync order terkait dan rebuild ringkasan bila perlu. Jangan menghapus event untuk menyembunyikan anomali. Nonaktif plugin hanya bila diperlukan dan siapkan rekonsiliasi setelah aktif kembali.
