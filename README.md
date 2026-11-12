# BagiRasa API

Backend REST API untuk platform berbagi makanan, pakaian bekas, dan barang layak pakai antara Pemberi dan Penerima di lingkungan lokal. Cocok juga untuk bantuan bencana alam dan kegiatan sosial. Nama "BagiRasa" masih sementara.

Arsitektur: decoupled client-server berbasis REST API. Repository ini hanya berisi backend. Frontend dikerjakan terpisah.

## Teknologi

- Laravel 12 (PHP 8.2 atau lebih baru)
- MySQL atau MariaDB (InnoDB)
- Laravel Sanctum (token bearer)

## Fitur Inti

- Auto-expiry: makanan wajib punya batas kelayakan, status efektif dihitung di server pada setiap request.
- Temporary locking: klaim mengunci item 30 menit memakai transaction dan `lockForUpdate()`, jadi tidak ada double-claim.
- Batas 2 klaim aktif per pengguna, penalti no-show (3 klaim hangus berturut-turut membuat akun dibatasi 24 jam).
- Tautan WhatsApp siap kirim, hanya muncul untuk Penerima dengan klaim aktif.
- Food Safety Guard: pernyataan layak, batas durasi per kategori, dan penolakan kata berisiko.
- Privasi: nomor WhatsApp dan lokasi detail tidak muncul di daftar publik.
- Laporan postingan, panel admin, notifikasi in-app, dan dashboard dampak.

## Prasyarat

| Kebutuhan | Keterangan |
|---|---|
| PHP 8.2+ | Cek dengan `php -v` |
| Composer | Cek dengan `composer -V` |
| MySQL atau MariaDB | Laragon dan XAMPP sama-sama bisa |
| Git | Cek dengan `git --version` |

Ekstensi PHP yang wajib aktif: `fileinfo`, `gd`, `mbstring`, `openssl`, `pdo_mysql`, `zip`.

### Catatan untuk pengguna XAMPP

Laragon sudah menyiapkan semuanya. Di XAMPP ada tiga hal yang dikerjakan satu kali:

1. Pastikan versi PHP 8.2 atau lebih baru. Kalau lebih lama, perbarui XAMPP.
2. Aktifkan ekstensi di atas lewat `php.ini` (hapus tanda `;` di depan barisnya), lalu restart terminal.
3. Pasang Composer secara terpisah, lalu tambahkan folder PHP XAMPP (misalnya `C:\xampp\php`) ke PATH Windows supaya perintah `php` dan `composer` bisa dipakai dari terminal.

Proyek dijalankan dengan `php artisan serve`, jadi tidak perlu `htdocs` atau virtual host. Lokasi folder proyek bebas.

## Instalasi

```bash
git clone https://github.com/arifarkan8/bagirasa-api.git
cd bagirasa-api
composer install
```

Salin file lingkungan, lalu buat kunci aplikasi.

Windows (Command Prompt atau Laragon):

```bash
copy .env.example .env
php artisan key:generate
```

macOS atau Linux:

```bash
cp .env.example .env
php artisan key:generate
```

Buat database kosong bernama `bagirasa` dengan collation `utf8mb4_unicode_ci`, lewat phpMyAdmin atau HeidiSQL. Lewat terminal:

```bash
mysql -u root -e "CREATE DATABASE bagirasa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Nilai bawaan di `.env.example` sudah cocok untuk Laragon dan XAMPP (`root` tanpa kata sandi). Kalau MySQL Anda memakai kata sandi, isi `DB_PASSWORD` di file `.env`.

Bangun tabel dan isi data dummy:

```bash
php artisan migrate --seed
php artisan storage:link
```

Jika perintah `storage:link` menjawab link sudah ada, abaikan saja.

## Menjalankan

```bash
php artisan serve --port=8001
```

API tersedia di `http://127.0.0.1:8001/api`. Port 8001 dipilih supaya tidak bentrok dengan aplikasi lain yang memakai port 8000. Biarkan terminal ini tetap terbuka selama server dipakai.

Tes cepat:

```bash
curl.exe http://127.0.0.1:8001/api/items
```

Hasil yang benar berupa JSON berisi `server_time` dan daftar `items`.

### Scheduler (opsional saat pengembangan)

Scheduler menyapu klaim yang lock-nya habis dan item yang kedaluwarsa tiap menit. Jalankan di terminal terpisah hanya saat ingin menguji fitur itu:

```bash
php artisan schedule:work
```

Tanpa scheduler, status tetap benar di setiap request karena dihitung langsung di server. Di server produksi, scheduler dijalankan lewat satu cron job:

```
* * * * * cd /path/ke/bagirasa-api && php artisan schedule:run >> /dev/null 2>&1
```

## Akun Dummy

Dibuat oleh seeder. Kata sandi semuanya `password123`. Hanya untuk pengembangan dan demo, jangan dipakai di server asli.

| Email | Peran |
|---|---|
| admin@bagirasa.test | admin |
| siti@bagirasa.test | pengguna |
| budi@bagirasa.test | pengguna |
| dewi@bagirasa.test | pengguna |
| rizky@bagirasa.test | pengguna |

Waktu pada data dummy dihitung relatif dari saat seeder dijalankan. Makanan akan kedaluwarsa dengan sendirinya, jadi jalankan ulang sebelum demo:

```bash
php artisan migrate:fresh --seed
```

Perintah itu menghapus semua data di database `bagirasa`.

## Autentikasi

Login lewat `POST /api/login` dan membalas `token`. Setiap permintaan berikutnya mengirim header:

```
Authorization: Bearer <token>
Accept: application/json
```

Token berlaku 7 hari. Pengguna tidak pernah mengetik token, frontend yang menyimpannya.

## Ringkasan Endpoint

| Method | Endpoint | Keterangan |
|---|---|---|
| POST | /api/register, /api/login | Autentikasi (publik) |
| POST | /api/logout | Keluar |
| GET, PATCH | /api/me | Profil sendiri |
| GET | /api/items | Daftar item, pencarian, dan filter (publik) |
| GET | /api/items/{id} | Detail item (publik, data sensitif hanya untuk yang berhak) |
| GET | /api/items/saya | Postingan milik sendiri |
| POST | /api/items | Buat item |
| POST, DELETE | /api/items/{id} | Ubah (POST) atau hapus item milik sendiri |
| POST | /api/items/{id}/claim | Klaim dan kunci item |
| POST | /api/items/{id}/report | Laporkan postingan |
| GET | /api/claims/me, /api/claims/masuk | Riwayat klaim, klaim yang masuk ke item saya |
| GET | /api/claims/{id} | Detail klaim |
| POST | /api/claims/{id}/complete, /cancel | Konfirmasi selesai, batalkan klaim |
| GET, POST | /api/notifications/... | Notifikasi in-app |
| GET | /api/stats/impact | Dashboard dampak (publik) |
| GET, PATCH | /api/admin/... | Panel admin (khusus admin) |

Daftar lengkap beserta contoh isi permintaan ada di `docs/API.md`. Daftar rute yang terpasang bisa dilihat dengan:

```bash
php artisan route:list --path=api
```

## CORS

Frontend yang boleh memanggil API diatur lewat `CORS_ALLOWED_ORIGINS` di `.env`, dipisah koma tanpa spasi. Kalau dikosongkan, dipakai `localhost:3000` dan `localhost:5173` (port bawaan Next.js dan Vite).

```
CORS_ALLOWED_ORIGINS=http://localhost:4200,https://contoh-frontend.com
```

Setelah mengubah `.env`, jalankan `php artisan config:clear`.

## Pengujian

### Uji otomatis

Uji otomatis berjalan di database terpisah bernama `bagirasa_test`, supaya data pengembangan tidak terhapus. Buat database kosong itu dulu (collation `utf8mb4_unicode_ci`), lalu:

```bash
php artisan config:clear
php artisan test
```

Tabelnya dibuat sendiri oleh tes. Di dalam tes ada penjaga yang menghentikan eksekusi kalau database aktif bukan `bagirasa_test`.

### Uji konkurensi (bukti anti double-claim)

Beberapa proses PHP terpisah mengklaim satu item pada detik yang sama. Hasil yang benar: tepat satu klaim berhasil.

```bash
php scripts/uji-konkurensi.php
php scripts/uji-konkurensi.php --permintaan=20 --putaran=5
```

Skrip ini memakai database `bagirasa`, tetapi hanya menyentuh akun berakhiran `@konkurensi.test` yang dihapus lagi di akhir. Skrip menolak jalan kalau `APP_ENV=production`.

## Struktur Singkat

```
app/Http/Controllers/Api/   Auth, Item, Claim, Report, Notifikasi, Admin, Stats
app/Models/                 User, Item, Claim, Report, Notifikasi, ActivityLog
app/Console/Commands/       RapikanKlaimDanItem (scheduler)
database/migrations/        Struktur tabel
database/seeders/           Data dummy
routes/api.php              Semua rute API
routes/console.php          Jadwal scheduler
scripts/                    Uji konkurensi
tests/Feature/              Uji otomatis
```

## Masalah yang Sering Muncul

| Pesan atau gejala | Penyebab dan solusi |
|---|---|
| `could not find driver` | Ekstensi `pdo_mysql` belum aktif (umum di XAMPP). Aktifkan di `php.ini`, restart terminal. |
| `Connection refused` | MySQL belum menyala. Klik Start All di Laragon atau Start MySQL di XAMPP. |
| `Unknown database 'bagirasa'` | Database belum dibuat atau namanya salah ketik. |
| `Access denied for user 'root'` | Root memakai kata sandi. Isi `DB_PASSWORD` di `.env`, lalu `php artisan config:clear`. |
| `No application encryption key` | Jalankan `php artisan key:generate`. |
| `Failed to listen on 127.0.0.1:8001` | Port dipakai aplikasi lain. Pakai `--port=8002` dan sesuaikan alamat di frontend. |
| Perubahan `.env` tidak terbaca | Jalankan `php artisan config:clear`, lalu restart server. |
| Foto tidak tampil | Jalankan `php artisan storage:link`. |

## Keamanan

- File `.env` tidak boleh masuk Git (sudah ada di `.gitignore`).
- Di server asli wajib `APP_DEBUG=false` dan `APP_ENV=production`, serta HTTPS.
- Ganti kata sandi akun dummy atau hapus akunnya sebelum dipakai publik.
- Waktu disimpan dalam UTC. Frontend menampilkannya dalam WIB.