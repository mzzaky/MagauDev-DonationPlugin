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

## Tambahan 1.0.6

| Endpoint | Izin | Fungsi |
|---|---|---|
| GET recent | Publik, limiter | Maksimal 12 pendukung terbaru yang menyetujui nama tampil: `name`, `amount`, `paid_at`; tanpa email/IP/penanda asal entri |
| GET config | Publik | Memuat `rewards: {tiers:[{id,label,basis,min_amount,items}]}` hanya bila hadiah aktif dan ada tingkat aktif, selain itu `null` |
| GET status/{reference} | Pemilik | Menambah `reward: {state,tier_label,items,xp,coin}`; `state`: `none|pending_claim|pending|granted` |
| GET/POST admin/rewards | mgdd_settings + nonce | Konfigurasi hadiah dengan revisi optimistik (409 bila basi); respons memuat `types`, `limits`, `warnings`, `adapter` |
| GET admin/rewards/log | mgdd_view + nonce | Log pemberian terpaginasi, filter `status` |
| POST admin/rewards/{id}/retry | mgdd_manage + nonce | Coba ulang satu hadiah `pending|failed` yang punya member tujuan |
| GET admin/member-search?q= | mgdd_manual + nonce | Maksimal 10 member: `id`, `name`, `login` (tanpa email) |
| POST admin/manual | mgdd_manual + nonce | Tambah entri manual; `reason` ≥8 karakter, `request_key` opsional untuk idempotensi |
| POST admin/manual/{id} | mgdd_manual + nonce | Ubah alias, pesan, visibilitas, catatan, tanggal, nominal; `revision` wajib, 409 bila basi |
| POST admin/manual/{id}/void | mgdd_manual + nonce | Batalkan entri (event pembalik); `reason` dan `revision` wajib |
| POST admin/donors/{id}/email | mgdd_sensitive + nonce | Ungkap email lengkap dengan alasan; diaudit |
| POST admin/donors/{id}/anonymize | mgdd_manage + nonce | Putus identitas profil; ledger tetap |

`GET admin/transactions` mendukung `source=gateway|manual` dan `voided=1` (entri dibatalkan disembunyikan secara bawaan); `GET admin/donors` mendukung `manual=1` dan `search`. Email pada daftar selalu tersamar (`email_mask`).

### Kontrak filter ke tema

- `apply_filters( 'mgdd_reward_types', array $types )`: jenis hadiah yang dapat diberikan, `id => {label,max}`; bawaan `xp` dan `coin`.
- `apply_filters( 'mgdd_reward_grant', null, array $reward )`: `$reward` = `{id,user_id,tier_id,label,basis,items,reference,amount}` tanpa PII. Mengembalikan `true` (tercatat, termasuk duplikat idempoten) → `granted`; `false` → `failed` (dicoba ulang dengan backoff); `null` (tak ada handler) → tetap `pending`.
- `do_action( 'mgdd_public_changed' )` setelah mutasi entri manual agar lapisan cache publik dapat diinvalidasi.
