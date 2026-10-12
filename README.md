# Khanza API MJKN (Mobile JKN)

Web service REST API untuk integrasi **Antrean BPJS Mobile JKN** dengan SIMRS Khanza. Dibuat dengan PHP native + MySQLi, tanpa framework.

## Fitur / Endpoint

| Method | Endpoint | Keterangan |
|--------|----------|------------|
| GET | `test` | Cek koneksi & waktu server |
| GET | `auth` | Ambil token JWT |
| POST | `statusantrean` | Cek sisa antrean poli |
| POST | `ambilantrean` | Ambil/booking antrean poli |
| POST | `checkinantrean` | Check-in antrean |
| POST | `batalantrean` | Batalkan antrean |
| POST | `sisaantrean` | Cek sisa antrean dari kode booking |
| POST | `jadwaloperasirs` | Jadwal operasi RS per rentang tanggal |
| POST | `jadwaloperasipasien` | Jadwal operasi per peserta |
| POST | `pasienbaru` | Registrasi pasien baru (dapat No. RM) |
| POST | `ambilantreanfarmasi` | Ambil antrean farmasi |
| POST | `statusantreanfarmasi` | Status antrean farmasi |

## Kebutuhan

- PHP 7.x ke atas (dengan ekstensi `mysqli`, `json`)
- MySQL / MariaDB (database SIMRS Khanza)
- Web server Apache/Nginx

## Instalasi

1. Clone ke folder web server:
   ```bash
   git clone https://github.com/firchun/khanza-api-mjkn-rs.git
   cd khanza-api-mjkn-rs
   ```

2. Salin file konfigurasi environment:
   ```bash
   cp .env-example .env
   ```

3. Sesuaikan kredensial database pada `.env`:
   ```dotenv
    DB_HOST=p:localhost
    DB_USER=root
    DB_PASS=
    DB_NAME=sik
   ```

   > Prefix `p:` pada `DB_HOST` menandakan **persistent connection** mysqli.

4. Pastikan `.env` tidak ikut ter-commit (sudah ada di `.gitignore`).

## Autentikasi

Semua endpoint (kecuali `test` dan `auth`) memerlukan header:

- `x-username` : username yang diberikan RS
- `x-token` : token JWT dari endpoint `auth`

### Ambil Token

```bash
curl -H "x-username: USERNAME" -H "x-password: PASSWORD" \
  "http://alamat-server/api-mjkn/?url=auth"
```

Token berlaku singkat (± 1 jam) dan dipakai untuk request berikutnya.

## Contoh Penggunaan

### Cek Status Antrean

```bash
curl -X POST "http://alamat-server/api-mjkn/?url=statusantrean" \
  -H "x-username: USERNAME" \
  -H "x-token: TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "kodepoli": "INT",
    "kodedokter": "123456",
    "tanggalperiksa": "2026-01-15",
    "jampraktek": "08:00-12:00"
  }'
```

### Ambil Antrean

```bash
curl -X POST "http://alamat-server/api-mjkn/?url=ambilantrean" \
  -H "x-username: USERNAME" \
  -H "x-token: TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "nomorkartu": "0001234567890",
    "nik": "1234567890123456",
    "nohp": "081234567890",
    "kodepoli": "INT",
    "kodedokter": "123456",
    "tanggalperiksa": "2026-01-15",
    "jampraktek": "08:00-12:00",
    "jeniskunjungan": "1",
    "nomorreferensi": "REF123456",
    "norm": "000123"
  }'
```

## Format Respons

```json
{
  "response": { },
  "metadata": {
    "message": "Ok",
    "code": 200
  }
}
```

`code` `200` menandakan sukses, selain itu pesan kesalahan ada pada `metadata.message`.

## Struktur Berkas

```
.
├── index.php          # Router & seluruh endpoint API
├── conf.php           # Loader .env, koneksi, & fungsi helper database
├── dokumentasi.html   # Dokumentasi tampilan
├── .env-example       # Contoh konfigurasi environment
└── .gitignore         # Mengabaikan .env
```

## Catatan Keamanan

- Jangan commit file `.env` (berisi kredensial database).
- Jaringan database idealnya dibatasi ke internal server saja.
