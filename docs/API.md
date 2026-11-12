# Dokumentasi API BagiRasa

Dokumen untuk pengembang frontend. Semua isi di sini mengikuti kode backend yang sedang berjalan. Kalau ada yang berbeda dengan perilaku sebenarnya, anggap kode sebagai sumber kebenaran dan kabari pemilik backend.

## Daftar Isi

1. [Ketentuan Umum](#1-ketentuan-umum)
2. [Nilai Tetap dan Data Referensi](#2-nilai-tetap-dan-data-referensi)
3. [Autentikasi](#3-autentikasi)
4. [Item](#4-item)
5. [Klaim](#5-klaim)
6. [Laporan](#6-laporan)
7. [Notifikasi](#7-notifikasi)
8. [Dashboard Dampak](#8-dashboard-dampak)
9. [Panel Admin](#9-panel-admin)
10. [Panduan Frontend](#10-panduan-frontend)

---

## 1. Ketentuan Umum

**Alamat dasar (pengembangan):** `http://127.0.0.1:8001/api`

**Header wajib di semua permintaan:**

```
Accept: application/json
```

**Header untuk rute yang butuh login:**

```
Authorization: Bearer <token>
```

**Content-Type:**

| Jenis permintaan | Content-Type |
|---|---|
| Tanpa berkas | `application/json` |
| Dengan foto (buat dan ubah item) | `multipart/form-data` (diatur otomatis oleh browser lewat `FormData`) |

### Waktu

- Semua waktu dalam respons berformat ISO 8601 UTC, contoh `2026-11-12T08:30:00+00:00`.
- Tampilkan ke pengguna dalam WIB (UTC+7).
- Waktu yang **dikirim** ke API sebaiknya juga berformat ISO 8601 dengan zona, contoh `2026-11-12T15:30:00+07:00`. Kalau dikirim tanpa zona, server menganggapnya UTC, sehingga jam WIB yang diketik pengguna bisa terbaca 7 jam di masa depan dan ditolak.
- Hampir semua respons memuat `server_time`. Pakai ini untuk menghitung mundur (lihat bagian 10).

### Pagination

Daftar yang dipaginasi memakai format bawaan Laravel:

```json
{
  "current_page": 1,
  "data": [],
  "first_page_url": "http://127.0.0.1:8001/api/items?page=1",
  "from": null,
  "last_page": 1,
  "last_page_url": "http://127.0.0.1:8001/api/items?page=1",
  "links": [],
  "next_page_url": null,
  "path": "http://127.0.0.1:8001/api/items",
  "per_page": 12,
  "prev_page_url": null,
  "to": null,
  "total": 0
}
```

Pindah halaman dengan `?page=2`. Isi daftarnya ada di `data`.

### Kode status dan bentuk error

| Kode | Arti | Bentuk isi |
|---|---|---|
| 200 | Berhasil | Sesuai endpoint |
| 201 | Berhasil dibuat | Sesuai endpoint |
| 401 | Belum login atau token tidak valid | `{"message":"Unauthenticated."}` |
| 403 | Tidak berhak | `{"message":"..."}` |
| 404 | Data tidak ditemukan | `{"message":"Data tidak ditemukan."}` atau pesan khusus |
| 422 | Validasi atau aturan bisnis gagal | Lihat di bawah |
| 429 | Terlalu banyak permintaan | `{"message":"..."}` dengan header `Retry-After` (detik) |

**Bentuk 422 validasi:**

```json
{
  "message": "The judul field is required.",
  "errors": {
    "judul": ["The judul field is required."]
  }
}
```

Pesan validasi bawaan Laravel berbahasa Inggris, sedangkan pesan aturan bisnis (misalnya Food Safety Guard) berbahasa Indonesia. Disarankan frontend menampilkan pesan sendiri berdasarkan **nama field** di `errors`, bukan menempelkan pesan server mentah-mentah.

**Rate limit:**

| Endpoint | Batas |
|---|---|
| `POST /register` | 5 per menit per IP |
| `POST /login` | 10 per menit per IP |
| `POST /items` | 20 per menit, dan 10 posting per hari per pengguna |
| `POST /items/{id}/claim` | 10 per menit |
| `POST /items/{id}/report` | 10 per menit |
| `GET /stats/impact` | 60 per menit |
| `GET /meta` | 60 per menit |

---

## 2. Nilai Tetap dan Data Referensi

Bagian ini memuat semua nilai tetap yang dipakai API. Daftar yang sama tersedia dalam bentuk JSON lewat `GET /meta` (lihat akhir bagian ini), jadi frontend tidak perlu menyalinnya ke kode.

### Jenis, kategori, dan kondisi item

| Jenis | Kategori yang diizinkan |
|---|---|
| `makanan` | `makanan_berat`, `kue_roti`, `minuman`, `makanan_kemasan` |
| `pakaian` | `pakaian_dewasa`, `pakaian_anak`, `selimut_kain`, `sepatu_tas` |
| `barang` | `perabot`, `elektronik`, `buku_alat_tulis`, `perlengkapan_bayi`, `kebutuhan_bencana`, `lainnya` |

Kondisi (hanya untuk pakaian dan barang): `baru`, `layak_pakai`, `perlu_perbaikan`.

### Batas masa kelayakan makanan (Food Safety Guard)

| Kategori | Maksimum (jam) |
|---|---|
| `makanan_berat` | 4 |
| `kue_roti` | 12 |
| `minuman` | 6 |
| `makanan_kemasan` | 72 |

Bawaan 3 jam bila `durasi_jam` tidak dikirim.

### Status

| Objek | Nilai |
|---|---|
| Item | `available`, `locked`, `completed`, `expired`, `cancelled`, `hidden` |
| Klaim | `active`, `completed`, `cancelled`, `expired` |
| Akun | `aktif`, `dibatasi`, `diblokir` |
| Laporan | `pending`, `diterima`, `ditolak` |

Status item dan klaim di respons adalah **status efektif**, dihitung server saat itu juga. Item yang lewat `expires_at` selalu tampil `expired` walaupun scheduler belum menyapunya. Klaim yang lock-nya lewat tampil `expired`.

### Alasan laporan

`basi`, `tidak_sesuai_foto`, `penipuan`, `konten_tidak_pantas`, `lainnya`

### Tipe notifikasi

`item_diklaim`, `klaim_dibatalkan`, `klaim_selesai`, `lock_hampir_habis`, `klaim_kedaluwarsa`, `item_disembunyikan`

### Batas unggah foto

Format jpg, jpeg, png, atau webp, ukuran maksimal 2 MB. Batas ini tidak ada di `GET /meta`, jadi tulis sebagai konstanta di frontend dan perbarui bila dokumen ini berubah.

### GET /meta

Publik, tanpa token. Mengirim semua daftar dan batas di atas dalam satu respons. Nilainya dibaca langsung dari kode backend, jadi bila backend menambah kategori atau alasan laporan baru, respons ini ikut berubah tanpa perlu mengubah dokumen atau frontend.

```json
{
  "jenis": ["makanan", "pakaian", "barang"],
  "kategori": {
    "makanan": ["makanan_berat", "kue_roti", "minuman", "makanan_kemasan"],
    "pakaian": ["pakaian_dewasa", "pakaian_anak", "selimut_kain", "sepatu_tas"],
    "barang": ["perabot", "elektronik", "buku_alat_tulis", "perlengkapan_bayi", "kebutuhan_bencana", "lainnya"]
  },
  "kondisi": ["baru", "layak_pakai", "perlu_perbaikan"],
  "makanan": {
    "durasi_bawaan_jam": 3,
    "durasi_maks_jam": {
      "makanan_berat": 4,
      "kue_roti": 12,
      "minuman": 6,
      "makanan_kemasan": 72
    }
  },
  "posting": { "maks_per_hari": 10 },
  "klaim": { "maks_aktif": 2, "durasi_lock_menit": 30 },
  "alasan_laporan": ["basi", "tidak_sesuai_foto", "penipuan", "konten_tidak_pantas", "lainnya"],
  "status": {
    "item": ["available", "locked", "completed", "expired", "cancelled", "hidden"],
    "klaim": ["active", "completed", "cancelled", "expired"],
    "akun": ["aktif", "dibatasi", "diblokir"],
    "laporan": ["pending", "diterima", "ditolak"]
  },
  "tipe_notifikasi": ["item_diklaim", "klaim_dibatalkan", "klaim_selesai", "lock_hampir_habis", "klaim_kedaluwarsa", "item_disembunyikan"]
}
```

Keterangan:

- `kategori` berbentuk objek yang kuncinya adalah `jenis`. Cocok untuk dropdown bertingkat: pilih jenis dulu, lalu kategorinya.
- Daftar kata terlarang Food Safety Guard sengaja tidak disertakan.
- Isi respons ini berubah jarang. Panggil sekali saat aplikasi dibuka, lalu simpan di memori.

---

## 3. Autentikasi

Objek `user` yang dikembalikan di endpoint autentikasi:

```json
{
  "id": 1,
  "name": "Siti Katering",
  "email": "siti@bagirasa.test",
  "no_whatsapp": "081200000001",
  "area": "Kampus Utara",
  "role": "user",
  "status_akun": "aktif",
  "dibatasi_sampai": null
}
```

`role` bernilai `user` atau `admin`. Peran Pemberi dan Penerima tidak ada di akun, orang yang sama bisa memposting dan mengklaim.

### POST /register

Publik.

| Field | Aturan |
|---|---|
| `name` | wajib, maksimal 100 karakter |
| `email` | wajib, format email, unik |
| `password` | wajib, minimal 8 karakter |
| `password_confirmation` | wajib, sama dengan `password` |
| `no_whatsapp` | wajib, diawali `08` atau `62`, hanya angka, total 10 sampai 15 digit |
| `area` | wajib, maksimal 100 karakter |
| `setuju_kebijakan` | wajib bernilai `true` |

Field `role` dan `status_akun` diabaikan, jadi tidak ada yang bisa mendaftar sebagai admin.

**201:**

```json
{
  "message": "Registrasi berhasil.",
  "user": { "id": 6, "name": "Tes User", "email": "tes@mail.com", "no_whatsapp": "081234567890", "area": "Kampus", "role": "user", "status_akun": "aktif", "dibatasi_sampai": null },
  "token": "1|zkJ4AZkF6RcCK68GF41D74lxtxR4G5Vh3o3pgsif27ecb7a1"
}
```

### POST /login

Publik. Body: `email`, `password`.

| Kode | Isi |
|---|---|
| 200 | `message`, `user`, `token` (bentuk sama dengan registrasi) |
| 422 | `{"message":"Email atau kata sandi salah."}` (sengaja sama untuk email tidak ada dan sandi salah) |
| 403 | `{"message":"Akun Anda diblokir. Hubungi admin."}` |

Akun berstatus `dibatasi` tetap bisa login, tetapi tidak bisa mengklaim sampai `dibatasi_sampai` lewat.

### POST /logout

Login. Tanpa body. Mencabut token yang sedang dipakai. Balasan: `{"message":"Logout berhasil."}`

### GET /me

Login. Balasan: `{"user": { ... }}`

### PATCH /me

Login. Semua field opsional, kirim yang ingin diubah saja: `name`, `no_whatsapp`, `area` (aturan sama dengan registrasi). Email dan kata sandi tidak bisa diubah lewat endpoint ini.

Balasan: `{"message":"Profil diperbarui.","user":{ ... }}`

---

## 4. Item

Objek item:

```json
{
  "id": 1,
  "judul": "Nasi Kotak Ayam Bakar",
  "deskripsi": "Sisa konsumsi seminar, dikemas rapi dan baru dimasak.",
  "jenis": "makanan",
  "kategori": "makanan_berat",
  "kondisi": null,
  "jumlah": 25,
  "foto_url": "http://127.0.0.1:8001/storage/items/abc123.jpg",
  "area": "Kampus Utara",
  "waktu_dibuat_makanan": "2026-11-12T01:15:00+00:00",
  "expires_at": "2026-11-12T04:00:00+00:00",
  "status": "available",
  "bisa_diklaim": true,
  "pemberi": { "id": 2, "name": "Siti Katering" },
  "created_at": "2026-11-12T01:50:00+00:00"
}
```

Keterangan:

- `foto_url` berisi alamat lengkap atau `null` bila tanpa foto.
- Untuk pakaian dan barang, `waktu_dibuat_makanan` selalu `null`, dan `expires_at` hanya terisi bila Pemberi mengisi `tersedia_sampai`.
- `bisa_diklaim` bernilai `true` hanya bila status efektif `available`. Pakai ini untuk mengaktifkan atau menonaktifkan tombol Klaim.

**Data sensitif.** Dua field tambahan hanya muncul untuk yang berhak (pemilik item, admin, atau Penerima dengan klaim aktif):

```json
{
  "lokasi_detail": "Lobi Gedung A, meja resepsionis",
  "pemberi": { "id": 2, "name": "Siti Katering", "no_whatsapp": "081200000001" }
}
```

Di daftar publik, dua field ini **tidak ada sama sekali** (bukan `null`, tetapi kuncinya hilang).

### GET /items

Publik. Daftar item yang masih tampil, yaitu berstatus `available` atau `locked` dan belum kedaluwarsa. Urutan: yang paling cepat kedaluwarsa dulu, item tanpa batas waktu di akhir.

| Query | Keterangan |
|---|---|
| `q` | kata kunci di judul dan deskripsi, maksimal 100 karakter |
| `jenis` | `makanan`, `pakaian`, atau `barang` |
| `kategori` | salah satu kategori |
| `area` | sama persis dengan area item |
| `per_page` | 1 sampai 50, bawaan 12 |
| `page` | nomor halaman |

**200:** `{"server_time":"...","items":{ ...paginasi, data berisi objek item... }}`

### GET /items/{id}

Publik, tetapi sebaiknya tetap kirim token bila pengguna sedang login, supaya `lokasi_detail` dan `no_whatsapp` ikut terkirim untuk yang berhak. Item berstatus `hidden` hanya terlihat oleh pemilik dan admin, selain itu 404.

**200:** `{"server_time":"...","item":{ ... }}`

### GET /items/saya

Login. Semua postingan milik sendiri, semua status (kecuali yang sudah dihapus), terbaru dulu, 12 per halaman. Data sensitif selalu ikut. Bentuk balasan sama dengan `GET /items`.

### POST /items

Login. Gunakan `multipart/form-data` bila menyertakan foto.

**Field umum:**

| Field | Aturan |
|---|---|
| `jenis` | wajib: `makanan`, `pakaian`, atau `barang` |
| `judul` | wajib, maksimal 150 karakter |
| `deskripsi` | opsional, maksimal 2000 karakter |
| `kategori` | wajib, harus sesuai jenis (lihat bagian 2) |
| `jumlah` | wajib, bilangan bulat 1 sampai 1000 |
| `foto` | opsional, gambar jpg, jpeg, png, atau webp, maksimal 2 MB |
| `area` | wajib, maksimal 100 karakter |
| `lokasi_detail` | wajib, maksimal 500 karakter |

**Tambahan bila `jenis` = `makanan`:**

| Field | Aturan |
|---|---|
| `waktu_dibuat_makanan` | wajib, tidak boleh di masa depan. Kirim ISO 8601 dengan zona |
| `durasi_jam` | opsional, bilangan bulat minimal 1, tidak boleh melebihi batas kategori. Bawaan 3 |
| `pernyataan_layak` | wajib bernilai `true` (pernyataan makanan layak konsumsi dan diolah higienis) |

Server menghitung `expires_at` sendiri: yang lebih awal antara (sekarang + `durasi_jam`) dan (`waktu_dibuat_makanan` + batas kategori). Frontend tidak bisa mengirim `expires_at`.

Judul dan deskripsi makanan ditolak bila memuat kata `mentah`, `basi`, `sisa makan`, atau `sisa piring`. Makanan yang sudah melewati batas kelayakan sejak dimasak juga ditolak.

**Tambahan bila `jenis` = `pakaian` atau `barang`:**

| Field | Aturan |
|---|---|
| `kondisi` | wajib: `baru`, `layak_pakai`, atau `perlu_perbaikan` |
| `tersedia_sampai` | opsional, tanggal di masa depan. Disimpan sebagai `expires_at` |

**201:** `{"message":"Item berhasil diposting.","server_time":"...","item":{ ... }}`

**Kemungkinan error:** 422 (validasi atau Food Safety Guard), 429 (batas 10 posting per hari: `{"message":"Batas posting harian tercapai. Coba lagi besok."}`).

### POST /items/{id}

Login. **Mengubah** item milik sendiri. Memakai `POST`, bukan `PUT` atau `PATCH`, karena PHP tidak membaca berkas yang dikirim lewat `PUT` atau `PATCH` bertipe form-data. Kirim sebagai `multipart/form-data` bila mengganti foto.

Semua field opsional, kirim yang berubah saja: `judul`, `deskripsi`, `kategori` (harus sesuai jenis item), `jumlah`, `foto`, `area`, `lokasi_detail`, dan `kondisi` (hanya pakaian dan barang). `jenis` dan masa berlaku tidak bisa diubah. Foto baru menggantikan foto lama.

| Kode | Penyebab |
|---|---|
| 200 | `{"message":"Item diperbarui.","server_time":"...","item":{ ... }}` |
| 403 | Bukan pemilik item |
| 422 | Item tidak berstatus `available` (sedang terkunci, selesai, atau kedaluwarsa), atau validasi gagal |

### DELETE /items/{id}

Login. Menghapus item milik sendiri (soft delete, datanya tetap ada di database).

| Kode | Penyebab |
|---|---|
| 200 | `{"message":"Item dihapus."}` |
| 403 | Bukan pemilik item |
| 422 | Item sedang diklaim, atau sudah selesai diserahkan |

---

## 5. Klaim

Objek klaim:

```json
{
  "id": 7,
  "kode_klaim": "BR-7K2Q",
  "status": "active",
  "locked_until": "2026-11-12T02:30:00+00:00",
  "sisa_detik": 1500,
  "dikonfirmasi_pemberi_at": null,
  "dikonfirmasi_penerima_at": null,
  "completed_at": null,
  "created_at": "2026-11-12T02:00:00+00:00",
  "item": {
    "id": 1,
    "judul": "Nasi Kotak Ayam Bakar",
    "jenis": "makanan",
    "area": "Kampus Utara",
    "status": "locked"
  }
}
```

Keterangan:

- `sisa_detik` adalah sisa waktu lock, `0` bila klaim tidak lagi `active`.
- `status` bernilai `expired` bila `locked_until` sudah lewat, walaupun scheduler belum merapikannya.
- Untuk **klaim aktif milik Penerima**, objek ini punya tiga tambahan:

```json
{
  "item": { "...": "...", "lokasi_detail": "Lobi Gedung A, meja resepsionis" },
  "pemberi": { "name": "Siti Katering", "no_whatsapp": "081200000001" },
  "tautan_whatsapp": "https://wa.me/6281200000001?text=Halo%2C%20saya%20..."
}
```

Klaim yang sudah selesai, dibatalkan, atau kedaluwarsa tidak membawa tiga tambahan ini.

### POST /items/{itemId}/claim

Login. Tanpa body. Mengklaim dan mengunci item.

**Aturan:**

- Lock berlangsung 30 menit, tetapi tidak pernah melewati `expires_at` item.
- Maksimal 2 klaim aktif per pengguna.
- Tidak bisa mengklaim item sendiri.
- Akun berstatus `dibatasi` (karena 3 klaim hangus berturut-turut) tidak bisa mengklaim selama 24 jam.

**201:** `{"message":"Klaim berhasil. Item dikunci untuk Anda.","server_time":"...","claim":{ ... }}`

| Kode | Penyebab |
|---|---|
| 403 | Akun dibatasi, pesan memuat waktu berakhirnya pembatasan |
| 404 | Item tidak ditemukan atau disembunyikan |
| 422 | Kesalahan ada di `errors.item`: item milik sendiri, sudah kedaluwarsa, sedang diklaim orang lain, atau jatah 2 klaim aktif sudah penuh |

Bila dua orang mengklaim bersamaan, hanya satu yang mendapat 201, yang lain mendapat 422 dengan pesan "Item ini sedang diklaim orang lain atau sudah tidak tersedia."

**Tautan WhatsApp.** `tautan_whatsapp` sudah berisi pesan awal (nama item, nama Penerima, kode klaim) dan nomor Pemberi dalam format internasional (`08...` diubah menjadi `628...`). Di bagian estimasi waktu tiba ada penanda `[isi estimasi waktu]` yang diisi Penerima. Bila frontend ingin pratinjau pesan yang bisa disunting sebelum dikirim, susun sendiri tautan `https://wa.me/<nomor>?text=<pesan terenkode>` dari `pemberi.no_whatsapp` dan `kode_klaim`.

### GET /claims/me

Login. Riwayat klaim sendiri, terbaru dulu, 12 per halaman. Balasan: `{"server_time":"...","claims":{ ...paginasi... }}`

### GET /claims/masuk

Login. Klaim yang masuk ke item milik sendiri (sisi Pemberi), 12 per halaman. Tiap klaim punya tambahan `penerima: {id, name}`. Nomor WhatsApp dan lokasi tidak disertakan di sini. Balasan: `{"server_time":"...","claims":{ ...paginasi... }}`

### GET /claims/{id}

Login. Hanya Penerima klaim, Pemberi item, atau admin. Selain itu 403. Data sensitif hanya ikut untuk Penerima dengan klaim aktif. Balasan: `{"server_time":"...","claim":{ ... }}`

### POST /claims/{id}/complete

Login. Tanpa body. Perilakunya bergantung pada siapa yang memanggil:

| Pemanggil | Efek | Pesan |
|---|---|---|
| Pemberi item | Klaim menjadi `completed`, item menjadi `completed`, hitungan no-show Penerima direset, Penerima mendapat notifikasi | `Serah terima selesai.` |
| Penerima | Hanya mencatat `dikonfirmasi_penerima_at`, klaim tetap `active` | `Konfirmasi Anda tercatat. Menunggu konfirmasi Pemberi.` |

Selesai hanya sah setelah **Pemberi** mengonfirmasi. 403 bila bukan salah satu pihak, 422 bila klaim sudah tidak aktif.

### POST /claims/{id}/cancel

Login. Tanpa body. Hanya Penerima klaim yang boleh membatalkan (selain itu 403). Klaim menjadi `cancelled`, item kembali `available` bila belum kedaluwarsa, dan Pemberi mendapat notifikasi. 422 bila klaim sudah tidak aktif. Pembatalan sukarela tidak menambah hitungan no-show.

Balasan: `{"message":"Klaim dibatalkan."}`

---

## 6. Laporan

### POST /items/{itemId}/report

Login. Melaporkan postingan bermasalah.

| Field | Aturan |
|---|---|
| `alasan` | wajib, salah satu nilai alasan di bagian 2 |
| `keterangan` | opsional, maksimal 500 karakter |

**201:**

```json
{
  "message": "Laporan terkirim. Terima kasih, admin akan meninjaunya.",
  "report": {
    "id": 3,
    "item_id": 9,
    "alasan": "tidak_sesuai_foto",
    "status_tinjauan": "pending",
    "created_at": "2026-11-12T02:10:00+00:00"
  }
}
```

| Kode | Penyebab |
|---|---|
| 404 | Item tidak ditemukan atau disembunyikan |
| 422 | Melaporkan item sendiri, atau sudah pernah melaporkan item yang sama |

Laporan tidak menyembunyikan item secara otomatis. Admin yang memutuskan.

---

## 7. Notifikasi

Objek notifikasi:

```json
{
  "id": 12,
  "tipe": "item_diklaim",
  "judul": "Item Anda diklaim",
  "isi": "Rizky Pratama mengklaim \"Nasi Bungkus Sayur\" dengan kode BR-7K2Q.",
  "item_id": 10,
  "claim_id": 7,
  "dibaca": false,
  "dibaca_at": null,
  "created_at": "2026-11-12T02:00:00+00:00"
}
```

`item_id` dan `claim_id` bisa dipakai untuk membuka halaman terkait saat notifikasi diklik. Keduanya bisa `null`.

Semua endpoint notifikasi memerlukan login dan hanya menyentuh notifikasi milik sendiri.

### GET /notifications

| Query | Keterangan |
|---|---|
| `belum_dibaca` | `1` untuk hanya yang belum dibaca |
| `per_page` | 1 sampai 50, bawaan 15 |

Balasan: `{"server_time":"...","jumlah_belum_dibaca":3,"notifications":{ ...paginasi... }}`

### GET /notifications/jumlah

Untuk angka merah di ikon lonceng. Balasan: `{"jumlah_belum_dibaca":3}`

### POST /notifications/{id}/baca

Menandai satu notifikasi dibaca. Balasan: `{"message":"Notifikasi ditandai dibaca.","notification":{ ... }}`. 404 bila bukan milik sendiri.

### POST /notifications/baca-semua

Balasan: `{"message":"Semua notifikasi ditandai dibaca.","jumlah_diubah":3}`

Tidak ada push notification. Frontend cukup memanggil `GET /notifications/jumlah` secara berkala (misalnya tiap 30 sampai 60 detik) dan saat halaman dibuka.

---

## 8. Dashboard Dampak

### GET /stats/impact

Publik, untuk landing page. Hasil di-cache 60 detik, jadi angka bisa terlambat sampai semenit.

```json
{
  "server_time": "2026-11-12T02:00:00+00:00",
  "dampak": {
    "total_klaim_selesai": 6,
    "total_unit": 90,
    "makanan": { "klaim_selesai": 3, "jumlah_unit": 65 },
    "pakaian": { "klaim_selesai": 2, "jumlah_unit": 13 },
    "barang": { "klaim_selesai": 1, "jumlah_unit": 12 }
  },
  "tersedia_sekarang": 11,
  "pengguna_terdaftar": 5,
  "pengguna_aktif_30_hari": 5,
  "mingguan": [
    { "minggu_mulai": "2026-09-14", "klaim_selesai": 0, "jumlah_unit": 0 },
    { "minggu_mulai": "2026-09-21", "klaim_selesai": 1, "jumlah_unit": 30 }
  ]
}
```

Keterangan:

- Angka dampak dihitung dari **klaim yang selesai** (dikonfirmasi Pemberi), bukan dari jumlah postingan.
- `jumlah_unit` adalah jumlah porsi atau barang menurut isian `jumlah` pada item. API belum menyediakan estimasi berat (kg).
- `mingguan` selalu berisi 8 minggu terakhir, minggu tanpa data bernilai 0. `minggu_mulai` adalah hari Senin menurut UTC.
- `pengguna_aktif_30_hari` adalah pengguna yang memposting atau mengklaim dalam 30 hari terakhir.

---

## 9. Panel Admin

Semua endpoint di bagian ini memerlukan login dengan `role` = `admin`. Pengguna biasa mendapat 403 dengan pesan "Hanya admin yang boleh mengakses ini.", dan tanpa token mendapat 401.

### GET /admin/laporan

| Query | Keterangan |
|---|---|
| `status` | `pending` (bawaan), `diterima`, atau `ditolak` |

Terlama dulu, 15 per halaman. Balasan: `{"server_time":"...","reports":{ ...paginasi... }}`. Isi tiap laporan:

```json
{
  "id": 1,
  "alasan": "tidak_sesuai_foto",
  "keterangan": "Kondisi barang berbeda dari deskripsi.",
  "status_tinjauan": "pending",
  "catatan_admin": null,
  "ditinjau_at": null,
  "created_at": "2026-11-12T02:10:00+00:00",
  "item": { "id": 9, "judul": "Kipas Angin Meja", "jenis": "barang", "status": "available" },
  "pelapor": { "id": 5, "name": "Rizky Pratama" },
  "peninjau": null
}
```

### PATCH /admin/laporan/{id}/tinjau

| Field | Aturan |
|---|---|
| `keputusan` | wajib: `diterima` atau `ditolak` |
| `catatan_admin` | opsional, maksimal 500 karakter |
| `sembunyikan_item` | opsional, boolean. Hanya berlaku bila `keputusan` = `diterima` |

Balasan: `{"message":"Laporan ditinjau."}`. 404 bila laporan tidak ada, 422 bila laporan sudah pernah ditinjau.

### PATCH /admin/items/{itemId}/sembunyikan

Body opsional: `alasan` (maksimal 500 karakter). Item menjadi `hidden`. Klaim aktif pada item itu dibatalkan **tanpa** menambah no-show, dan Penerima serta Pemberi mendapat notifikasi. Balasan: `{"message":"Item disembunyikan."}`. 404 bila item tidak ada atau sudah tersembunyi.

### PATCH /admin/items/{itemId}/pulihkan

Mengembalikan item `hidden`. Hasilnya `available` bila belum kedaluwarsa, selain itu `expired`. Balasan: `{"message":"Item dipulihkan.","status":"available"}`. 422 bila item tidak sedang disembunyikan.

### GET /admin/akun

| Query | Keterangan |
|---|---|
| `status_akun` | `aktif`, `dibatasi`, atau `diblokir` |
| `q` | cari di nama dan email |

20 per halaman. Balasan: `{"server_time":"...","users":{ ...paginasi... }}`. Isi tiap akun:

```json
{
  "id": 3,
  "name": "Budi Santoso",
  "email": "budi@bagirasa.test",
  "no_whatsapp": "081200000002",
  "area": "Kampus Selatan",
  "role": "user",
  "status_akun": "aktif",
  "no_show_count": 0,
  "dibatasi_sampai": null,
  "created_at": "2026-11-12T01:50:00+00:00"
}
```

### PATCH /admin/akun/{userId}/blokir

Body opsional: `alasan`. Status akun menjadi `diblokir` dan **semua token login akunnya dicabut**, jadi akun itu langsung mendapat 401 di semua perangkat. Balasan: `{"message":"Akun diblokir."}`. 422 bila admin memblokir diri sendiri, memblokir sesama admin, atau akun sudah diblokir.

### PATCH /admin/akun/{userId}/pulihkan

Mengembalikan akun yang `diblokir` atau `dibatasi` menjadi `aktif` dan mereset `no_show_count`. Balasan: `{"message":"Akun dipulihkan."}`. 422 bila akun sudah aktif. Akun yang diblokir harus login ulang, karena tokennya sudah dicabut.

### GET /admin/log

| Query | Keterangan |
|---|---|
| `aksi` | nama aksi, contoh `klaim_dibuat` |
| `user_id` | filter berdasarkan pelaku |

Terbaru dulu, 25 per halaman. Balasan: `{"server_time":"...","logs":{ ...paginasi... }}`. Isi tiap log:

```json
{
  "id": 40,
  "aksi": "klaim_dibuat",
  "user": { "id": 5, "name": "Rizky Pratama" },
  "target_tipe": "claim",
  "target_id": 7,
  "detail": { "item_id": 10 },
  "ip_address": "127.0.0.1",
  "created_at": "2026-11-12T02:00:00+00:00"
}
```

`user` bernilai `null` untuk tindakan otomatis oleh scheduler. Contoh nilai `aksi`: `akun_dibuat`, `login`, `item_dibuat`, `item_diubah`, `item_dihapus`, `klaim_dibuat`, `klaim_dibatalkan`, `klaim_selesai`, `klaim_dikonfirmasi_penerima`, `no_show_dicatat`, `item_dilaporkan`, `laporan_ditinjau`, `item_disembunyikan`, `item_dipulihkan`, `akun_diblokir`, `akun_dipulihkan`.

---

## 10. Panduan Frontend

### Menyimpan token

Simpan `token` dari login atau registrasi (misalnya di `localStorage`), lalu pasang di header `Authorization` pada setiap permintaan. Bila ada respons **401** pada rute yang butuh login, hapus token dan arahkan pengguna ke halaman login. Pengguna tidak pernah mengetik token.

### Memuat daftar pilihan dari /meta

Panggil `GET /meta` sekali saat aplikasi dibuka dan simpan hasilnya. Pakai untuk mengisi dropdown jenis, kategori, kondisi, alasan laporan, dan untuk membatasi isian durasi makanan, jadi tidak ada daftar yang diketik ulang di frontend.

```js
let meta = null;

async function muatMeta() {
  const res = await fetch(`${API}/meta`, { headers: { Accept: 'application/json' } });
  meta = await res.json();
}

// Dropdown kategori bertingkat: pilih jenis dulu
const kategoriUntuk = (jenis) => meta.kategori[jenis] ?? [];

// Batas durasi makanan untuk kategori terpilih
const maksJam = (kategori) => meta.makanan.durasi_maks_jam[kategori];
```

Tampilkan label yang ramah pengguna lewat satu kamus di frontend (misalnya `makanan_berat` menjadi "Makanan berat"), dan beri cabang bawaan untuk nilai yang belum ada di kamus, supaya kategori baru dari backend tetap tampil walau labelnya belum dibuat.

### Countdown yang sinkron dengan server

Jam perangkat pengguna bisa salah, jadi hitung selisihnya terhadap `server_time` sekali saat data dimuat:

```js
// Dipanggil sekali setiap kali respons API diterima
const selisihJam = Date.parse(respons.server_time) - Date.now();

// Sisa milidetik sampai batas waktu (expires_at atau locked_until)
function sisaMs(batasIso) {
  return Date.parse(batasIso) - (Date.now() + selisihJam);
}
```

Countdown di layar hanyalah tampilan. Keputusan sebenarnya selalu di server, jadi walaupun tampilan masih menunjukkan sisa waktu, klaim yang terlambat tetap ditolak dengan 422.

### Warna status waktu

Sesuai rancangan antarmuka:

| Sisa waktu | Warna |
|---|---|
| Lebih dari 1 jam | Hijau |
| Kurang dari 1 jam | Kuning |
| Kurang dari 15 menit | Merah |

Selalu sertakan teks atau ikon di samping warna, supaya tetap terbaca oleh pengguna buta warna.

### Tombol Klaim

Aktifkan hanya bila `bisa_diklaim` bernilai `true`. Bila nonaktif, tampilkan alasannya berdasarkan `status`:

| `status` item | Alasan yang ditampilkan |
|---|---|
| `locked` | Sedang diklaim orang lain |
| `expired` | Sudah melewati batas kelayakan |
| `completed` | Sudah selesai diserahkan |

Tetap tangani respons 422 saat klaim, karena orang lain bisa mendahului dalam hitungan detik.

### Alur layar utama

- **Penerima:** beranda, detail item, klaim, halaman klaim aktif (tampilkan `kode_klaim`, `sisa_detik`, `lokasi_detail`, tombol `tautan_whatsapp`), bertemu, konfirmasi selesai.
- **Pemberi:** posting, terima notifikasi `item_diklaim`, WhatsApp, serah terima, tekan konfirmasi selesai lewat `POST /claims/{id}/complete`.

### Mengunggah foto

```js
const form = new FormData();
form.append('jenis', 'makanan');
form.append('judul', 'Nasi Kotak');
form.append('kategori', 'makanan_berat');
form.append('jumlah', 10);
form.append('area', 'Kampus Utara');
form.append('lokasi_detail', 'Lobi Gedung A');
form.append('waktu_dibuat_makanan', new Date().toISOString());
form.append('pernyataan_layak', 1);
form.append('foto', berkasFoto);

fetch('http://127.0.0.1:8001/api/items', {
  method: 'POST',
  headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  body: form, // jangan atur Content-Type sendiri, browser mengisinya
});
```

### Pemetaan error validasi ke form

Baca `errors` dari respons 422, lalu tampilkan pesan di bawah field yang sesuai (kunci `errors` sama dengan nama field). Kesalahan aturan klaim muncul di kunci `item`, dan kesalahan Food Safety Guard muncul di `judul`, `durasi_jam`, `waktu_dibuat_makanan`, atau `pernyataan_layak`.

### Hal yang perlu diingat

- Ubah item memakai `POST /items/{id}`, bukan `PUT`.
- Pengguna biasa yang membuka `/api/admin/...` mendapat 403, jadi sembunyikan menu admin berdasarkan `user.role`, tetapi jangan mengandalkannya sebagai pengaman.
- Alamat frontend yang boleh memanggil API diatur backend lewat `CORS_ALLOWED_ORIGINS`. Bila browser menolak dengan pesan CORS, minta pemilik backend menambahkan alamat frontend Anda.
- Foto diambil langsung dari `foto_url` memakai tag gambar biasa, tanpa header khusus.
- Daftar kategori, kondisi, alasan laporan, dan batas durasi dibaca dari `GET /meta`, bukan ditulis ulang. Hanya batas unggah foto (2 MB) yang perlu ditulis sendiri di frontend.