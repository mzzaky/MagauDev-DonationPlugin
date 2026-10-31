# API Magaudev Donations v1

Base `/wp-json/magaudev-donations/v1/`. JSON, body maksimum 16 KiB; webhook 64 KiB. Error `{code,message,data:{status,request_id}}`. Tidak ada endpoint yang menerima status paid dari browser.

| Endpoint | Izin | Fungsi |
|---|---|---|
| GET config, summary, top?period=month/all&page=1 | Publik, limiter | DTO tersaring; production saja |
| POST session | Origin sama, JSON | Cookie HttpOnly dan csrf sesi; bukan akun WP |
| POST checkout | Sesi, X-MGDD-CSRF, origin; nonce WP untuk member | amount, name, email, message, public_name, public_message, idempotency_key, website, captcha |
| GET status/{reference} | Pemilik member/sesi | Ringkasan privat, token checkout miliknya jika masih sah |
| POST status/{reference}/sync | Pemilik + CSRF | Ambil GET Status, tidak percaya callback Snap |
| POST status/{reference}/retry | Pemilik + CSRF | Retry eksplisit terminal; tidak pada unknown/pending/paid |
| POST verify/request | Sesi + CSRF | Kirim kode pada email milik intent; respons generik |
| POST verify/consume | Sesi + CSRF | Kode sekali pakai; mode verify/claim/recover |
| POST recover | Sesi + CSRF | Email, respons generik; pemulihan tanpa order ID sebagai autentikasi |
| POST privacy/{reference} | Pemilik + CSRF | Persetujuan nama/pesan per intent |
| POST webhook | Signature + verifikasi API gateway | Event finansial durable; tanpa cookie/nonce |
| GET admin/overview, transactions, donors, logs, settings | Capability terkait + nonce WP | Data dibatasi dan pagination |
| GET admin/transactions/{id} | mgdd_view + nonce | Attempt dan timeline tersaring |
| POST admin/transactions/{id} | mgdd_manage + nonce | sync, note, moderation; alasan wajib |
| POST admin/settings | mgdd_settings + nonce | Revision optimistic concurrency, allowlist validasi |
| POST admin/reconcile, rebuild | mgdd_manage + nonce | Batch, ledger rebuild idempoten |
| POST admin/ip/{id} | mgdd_sensitive + nonce | Alasan, reveal audit, hasil no-store |
| GET/POST admin/security | mgdd_security + nonce | CIDR block sementara, limiter, retensi |
| POST admin/export | mgdd_export + nonce | CSV aman, maksimum 1000 baris dan rentang 93 hari |
| POST admin/log-export | mgdd_export + nonce | CSV audit tersaring tanpa IP/token, batas yang sama |
| GET mine | Sesi/member pemilik | Maksimal 100 referensi milik sesi/akun; bukan query email |
| GET messages | Publik | Maksimal 12 pesan yang disetujui donatur dan moderator, pembayaran produksi positif |

Sesi guest tidak memberi akses hanya dengan reference/order/email. Semua respons privat `Cache-Control: private, no-store`, referrer no-referrer. Idempotency key 16–80 karakter URL-safe; payload berbeda di scope/key sama HTTP409. Pending/unknown hasil timeout tidak otomatis retry order. Status 429 saat limiter terlampaui; 503 jika paused/config belum siap. Data sandbox tidak masuk statistik publik.
