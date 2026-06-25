# ar — Aplikasi Accounts Receivable (Suite ERP DKM)

> Aplikasi Laravel berdiri sendiri untuk modul **AR**: Invoice → Payment → (opsional) Credit Note, plus laporan AR (Open/Aged/Aged Summary/History Receivables). App kedua dalam suite ERP baru PT. Dharma Karyatama Mulia (DKM), setelah `sls`. Lihat `C:\Project\Web\sls\CLAUDE.md` untuk konteks suite secara umum dan `C:\Project\Web\erp-schema\MODULES-ROADMAP.md` untuk rencana modul lain.

## Cakupan

- **Invoice bisa dibuat dua cara**: dari Sales Order `sls` yang sudah `selesai` (auto-tarik customer/ship-to/lines), **atau** manual berdiri sendiri (untuk billing jasa/lain-lain yang tidak lewat `sls`) — keputusan eksplisit user, bukan dibatasi cuma dari SO seperti BS1 aslinya yang sebenarnya juga mendukung keduanya.
- **Payment** mengalokasikan satu pembayaran ke banyak invoice sekaligus, dengan opsi diskon (early payment) dan write-off per alokasi (write-off butuh pilih GL account).
- **Credit Note** mengurangi sisa tagihan invoice (retur/pembatalan), tervalidasi tidak boleh melebihi sisa owing invoice asal.
- **Posting GL otomatis** (retrofit 2026-06-23, setelah app `gl` dibangun) — `approve()` Invoice dan Payment masing-masing posting jurnal balanced ke `gl_journals`/`gl_journal_lines` (model `GlJournal`/`GlJournalLine`/`GlSetting` di-duplikasi ke app ini, pola sama seperti `ap`/`prc`/`inv`). Lihat detail entry jurnal di `C:\Users\hasyi\.claude\plans\floating-inventing-rabbit.md` atau langsung baca `ArInvoiceController::postInvoiceJournal()`/`ArPaymentController::postPaymentJournal()`.
- **Tidak ada bank reconciliation sungguhan** — cuma flag `reconciled` di `ar_payments`, bukan layar rekonsiliasi penuh.

## Reports

6 laporan di `ReportController.php`, 4 pertama mengikuti struktur wizard "AR Reports" BS1, 2 terakhir (2026-06-24) mengikuti wizard "Sales Reports" BS1:
- **Open Receivables** — flat list invoice belum lunas per customer, tanpa age bucket (No Invoice/Tanggal/Jatuh Tempo/Total/Dibayar/Owing).
- **Aged Receivables** — detail per-invoice dengan age bucket (current/1-30/31-60/61-90/90+), grouped per customer dengan subtotal.
- **Aged Receivables Summary** — rollup per customer saja (cuma total per bucket, tanpa baris invoice) — versi report "Aged Receivables" yang lama sebelum 2026-06-24 (sebelumnya dinamai "Aged Receivables" tanpa kata Summary, ternyata levelnya summary; sudah dipisah jadi report sendiri dan "Aged Receivables" di-upgrade ke detail sungguhan).
- **AR History** — gabungan ArInvoice+ArPayment dalam rentang tanggal (filter `date_from`/`date_to`, default bulan ini), grouped per customer, urut by date.
- **Sales Analysis** (`salesAnalysis()`) — konsolidasi 8 varian "Sales by ..." BS1 (Type, Type/Item, Type/Item/Customer, Customer, Customer/Type/Item, Salesman, Customer Type, Customer Type/Customer) jadi **satu** report dengan selector `group_by`, bukan 8 method/view terpisah seperti BS1 — keputusan eksplisit user untuk menyederhanakan. Sumber data `ar_invoice_lines` dari invoice `disetujui`/`selesai`, grouping rekursif (helper `groupSalesRows()`) sampai 3 level dalam, dirender lewat partial rekursif `reports/partials/sales-analysis-group.blade.php`. Butuh model baru `CustomerType`/`Salesman` (tabel `customer_types`/`salesmen`, sebelumnya tidak ada model di app ini) + relasi `Customer::customerType()`/`salesman()` baru.
- **Sales Invoice Register** (`salesInvoiceRegister()`) — flat list invoice posted per rentang tanggal, report ke-9 dari wizard BS1 yang sama (satu-satunya yang tidak digabung ke Sales Analysis karena tidak punya dimensi group-by).

## Computed Accessor, bukan Stored Column (penting!)

`ArInvoice::paid_amount`/`disc_taken_amount`/`write_off_amount`/`owing` **semua accessor**, dihitung dari relasi `allocations` (`ArPaymentAllocation`) — **bukan kolom tersimpan**. Migration awal `erp-schema` (dibuat sebelum field BS1 dibaca detail) sempat punya kolom `amount`/`paid_amount` tersimpan di `ar_invoices` — sudah di-drop lewat migration susulan (`2025_01_03_000001_add_ar_invoice_payment_fields.php`), diganti accessor, konsisten dengan pola `SalesOrder::total` di `sls`.

**Gotcha yang sempat kena (sudah diperbaiki, jangan diulang):** accessor `paid_amount`/dst awalnya menghitung dari **semua** allocation tanpa peduli status payment-nya — akibatnya invoice yang baru dibuat payment **draft** (belum di-approve) langsung kelihatan owing-nya berkurang. Fix: `ArInvoice::confirmedAllocations()` filter `allocations` yang payment-nya `disetujui`/`selesai` saja. Pola yang sama (filter by related-record status) sudah dipakai `SalesOrderLine::qty_shipped` di `sls` — terapkan pola ini lagi kalau bikin accessor serupa di app lain.

## Model Read-Only Lintas App

`Customer`/`Item`/`Shipto`/`Warehouse`/`Bank`/`GlAccount`/`Tax` — model biasa (tabel shared `erp`, tanpa migration di app ini). `SalesOrder`/`SalesOrderLine` di app ini **read-only** (tidak ada `$fillable`, tidak pernah ditulis) — `ar` cuma baca SO `sls` yang `selesai` untuk dijadikan dasar invoice, tidak pernah insert/update ke tabel `sls_*`.

## RBAC & Struktur

Identik dengan `sls`: role `sso_admin|admin|user|approval|viewer`, `/dev-login` untuk dev lokal (`AR_DEV_LOGIN_ENABLED`), tabel `ar_users`/`ar_sessions`/`ar_cache`/`ar_jobs` dst (prefix `ar_`, app punya users/framework table sendiri meski database `erp` dipakai bersama). `AutoNumberService` prefix: `INV` (invoice), `RCT` (payment/receipt), `CN` (credit note).

## Dev Lokal

SSO lokal sungguhan (bukan dev-login) — terdaftar di SSO lokal lewat `AddArApplicationSeeder.php` (sama file dipakai utk production, beda database target). `AR_DEV_LOGIN_ENABLED=false` di `.env` lokal — entry point selalu lewat Portal SSO (`localhost:8080/portal`), konsisten dengan pola `sls`. `/dev-login` masih ada di kode tapi tidak dipakai untuk app ini (sengaja, atas permintaan eksplisit user — "coba pelajari konsep aplikasi SSO dengan aplikasi lain misalnya sales").

## Deployment

✅ **Live di production**: `https://ar.dkmapps.com` — app code, SSO registration (kode `AR`), nginx vhost, dan DNS record semua sudah selesai dan terverifikasi (`302` redirect ke Portal SSO). Catatan: DNS subdomain `*.dkmapps.com` **bukan** wildcard otomatis — setiap app baru perlu DNS record ditambahkan manual (lihat `sls/CLAUDE.md` bagian Deployment).

**Jangan jalankan `db:seed` di production** — `UserSeeder` isinya 5 user dev-login fiktif (email placeholder `admin@dkmapps.com` dst), cuma untuk testing lokal. Sempat salah dijalankan sekali di production saat deploy awal, langsung dihapus manual. Production cukup `migrate --force` saja.

## Status & Verifikasi

✅ Alur penuh sudah dicoba (lokal + production) via curl/SSO sungguhan: buat invoice dari SO `selesai` → submit → approve (posting jurnal GL balanced) → catat payment (partial + write-off) → submit → approve (posting jurnal GL balanced) → owing terupdate benar → buat credit note partial → submit → approve → owing berkurang lagi → cek di semua 4 report AR. Juga dicoba invoice manual standalone (tanpa SO) — `due_date` terhitung benar dari `invoice_date + term_days`. RBAC dicoba (viewer diblokir dari create, tetap bisa lihat index). Login via SSO Portal sungguhan (bukan dev-login) sudah dicoba berkali-kali, lokal maupun production.

⏳ Belum dicoba: tampilan visual penuh di browser asli untuk semua halaman (kebanyakan verifikasi lewat HTTP/curl + Playwright, bukan klik manual satu-satu).
