# Labour API — คู่มือการใช้งาน

REST API สำหรับให้แอปพลิเคชันภายนอกดึงข้อมูล Labour จากระบบ
Charoenmun-Labours

- **Base URL (prod):** `https://charoenmunconcrete.net`
- **Prefix:** `/api/v1`
- **Auth:** Static API Key ผ่าน HTTP header `X-API-KEY`
- **Response:** JSON (UTF-8)

---

## 1. Authentication

ทุก request ต้องแนบ API Key มากับ header:

```
X-API-KEY: <your-api-key>
```

หรือ (กรณีจำเป็น เช่น ทดสอบบน browser) สามารถส่งเป็น query / body parameter:

```
?api_key=<your-api-key>
```

> **หมายเหตุ:** การส่งคีย์ผ่าน query string จะถูกบันทึกใน server log และ browser
> history ควรใช้ header เป็นหลัก

### การตั้ง API Key

ใน `.env` ของฝั่ง server:

```env
LABOUR_API_KEY=your-secret-key
```

สุ่มคีย์ใหม่:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

หลังแก้ `.env` ต้อง:

```bash
php artisan config:clear
# แล้ว restart php artisan serve
```

### Response เมื่อ Auth ล้มเหลว

| สถานการณ์                                     | HTTP | Body                                                                       |
| --------------------------------------------- | ---- | -------------------------------------------------------------------------- |
| ไม่ส่ง key หรือ key ผิด                       | 401  | `{"status":"error","message":"Unauthorized. Invalid or missing API key."}` |
| Server ยังไม่ได้ตั้ง `LABOUR_API_KEY` ใน .env | 500  | `{"status":"error","message":"API key is not configured on the server."}`  |

---

## 2. Endpoints

### 2.1 GET `/api/v1/labours`

ดึงรายการ labour (มี filter / pagination)

#### Query parameters

| Parameter            | Type        | Default | คำอธิบาย                                                                            |
| -------------------- | ----------- | ------- | ----------------------------------------------------------------------------------- |
| `search`             | string      | —       | ค้นหาแบบ LIKE ใน `passport_number`, `fullname`, `fullname_th`, `labour_number`      |
| `company_id`         | int         | —       | กรองตามบริษัท                                                                       |
| `labour_agency`      | int         | —       | กรองตาม agency                                                                      |
| `labour_status`      | string      | —       | กรองตามสถานะ labour                                                                 |
| `labour_status_job`  | string      | —       | กรองตามสถานะการทำงาน                                                                |
| `labour_nationality` | string      | —       | กรองตามสัญชาติ                                                                      |
| `per_page`           | int / `all` | `20`    | จำนวนต่อหน้า (1–200) หรือ `all` เพื่อดึงทั้งหมด                                     |
| `all`                | bool (`1`)  | —       | ถ้าใส่ `all=1` จะดึงทั้งหมดเช่นเดียวกับ `per_page=all`                              |
| `page`               | int         | `1`     | หน้าที่ต้องการ (เฉพาะกรณี paginate)                                                 |

#### ตัวอย่าง

```http
GET /api/v1/labours?per_page=50&page=1
GET /api/v1/labours?search=สมชาย
GET /api/v1/labours?company_id=12&labour_status_job=working
GET /api/v1/labours?per_page=all
```

#### Response (paginated)

```json
{
  "data": [
    {
      "labour_id": 123,
      "labour_prefix": "นาย",
      "labour_number": "L-0001",
      "labour_fullname": "Somchai Jaidee",
      "labour_fullname_th": "สมชาย ใจดี",
      "labour_sex": "male",
      "labour_nationality": "MM",
      "labour_birthday": "1990-05-12",
      "labour_status": "active",
      "labour_status_job": "working",
      "labour_jobdate_start": "2024-01-15",
      "labour_resing_date": null,
      "labour_escape_date": null,
      "passport": {
        "number": "AB1234567",
        "date_start": "2023-05-01",
        "date_end": "2028-04-30"
      },
      "visa": {
        "number": "V-998877",
        "date_in": "2024-01-10",
        "date_start": "2024-01-10",
        "date_end": "2026-01-09"
      },
      "work_permit": {
        "number": "WP-55667788",
        "labour_no": "12345",
        "date_start": "2024-01-15",
        "date_end": "2026-01-14"
      },
      "day90": {
        "date_start": "2024-01-15",
        "date_end": "2024-04-14"
      },
      "tm_number": "TM-001",
      "note": "พนักงานครัว",
      "company": {
        "company_id": 12,
        "company_name": "ABC Restaurant Co., Ltd.",
        "company_tax": "0105500000123"
      },
      "agency": {
        "agency_id": 3,
        "agency_name": "Best Agency"
      },
      "created_at": "2024-01-10T03:21:55.000000Z",
      "updated_at": "2024-08-12T07:45:01.000000Z"
    }
  ],
  "links": {
    "first": "http://127.0.0.1:5168/api/v1/labours?page=1",
    "last":  "http://127.0.0.1:5168/api/v1/labours?page=10",
    "prev":  null,
    "next":  "http://127.0.0.1:5168/api/v1/labours?page=2"
  },
  "meta": {
    "current_page": 1,
    "last_page": 10,
    "per_page": 50,
    "total": 487
  },
  "status": "success"
}
```

#### Response (per_page=all)

```json
{
  "data": [ /* ... ทุกรายการ ... */ ],
  "status": "success",
  "meta": {
    "total": 487,
    "paginate": false
  }
}
```

> ⚠️ ควรหลีกเลี่ยง `per_page=all` กับฐานข้อมูลขนาดใหญ่ เพราะกินทั้ง memory
> และ bandwidth ใช้ pagination ปกติเป็นทางเลือกแรก

---

### 2.2 GET `/api/v1/labours/{id}`

ดึงข้อมูล labour รายบุคคลด้วย `labour_id`

#### ตัวอย่าง

```http
GET /api/v1/labours/123
```

#### Response (200)

```json
{
  "data": {
    "labour_id": 123,
    "labour_fullname": "Somchai Jaidee",
    "...": "..."
  },
  "status": "success"
}
```

#### Response (404)

```json
{
  "status": "error",
  "message": "Labour not found."
}
```

---

### 2.3 GET `/api/v1/labours/passport/{passport}`

ค้นหา labour ด้วยเลขพาสปอร์ต (exact match)

#### ตัวอย่าง

```http
GET /api/v1/labours/passport/AB1234567
```

#### Response (200) — โครงสร้างเหมือน 2.2

#### Response (404)

```json
{
  "status": "error",
  "message": "Labour not found for the given passport number."
}
```

---

## 3. โครงสร้างข้อมูล (Labour Object)

| Field                  | Type            | คำอธิบาย                              |
| ---------------------- | --------------- | ------------------------------------- |
| `labour_id`            | int             | Primary key                           |
| `labour_prefix`        | string\|null    | คำนำหน้าชื่อ                          |
| `labour_number`        | string\|null    | รหัสภายในของบริษัท                    |
| `labour_fullname`      | string          | ชื่อเต็ม (ภาษาอังกฤษ)                 |
| `labour_fullname_th`   | string\|null    | ชื่อเต็ม (ภาษาไทย)                    |
| `labour_sex`           | string          | `male` / `female` หรือค่าตามต้นทาง    |
| `labour_nationality`   | string\|null    | รหัสสัญชาติ (เช่น `MM`, `KH`, `LA`)   |
| `labour_birthday`      | date (Y-m-d)    | วันเกิด                               |
| `labour_status`        | string\|null    | สถานะของแรงงาน                        |
| `labour_status_job`    | string\|null    | สถานะการทำงาน                         |
| `labour_jobdate_start` | date            | วันเริ่มงาน                           |
| `labour_resing_date`   | date\|null      | วันลาออก                              |
| `labour_escape_date`   | date\|null      | วันหลบหนี                             |
| `passport.number`      | string          | เลขที่หนังสือเดินทาง                  |
| `passport.date_start`  | date            | วันออก                                |
| `passport.date_end`    | date            | วันหมดอายุ                            |
| `visa.number`          | string\|null    | เลขที่วีซ่า                           |
| `visa.date_in`         | date\|null      | วันเดินทางเข้าประเทศ                  |
| `visa.date_start`      | date\|null      | วันเริ่มวีซ่า                         |
| `visa.date_end`        | date\|null      | วันหมดอายุวีซ่า                       |
| `work_permit.number`   | string\|null    | เลขที่ใบอนุญาตทำงาน                   |
| `work_permit.labour_no`| string\|null    | เลขประจำตัวแรงงาน                     |
| `work_permit.date_start`| date\|null     | วันเริ่ม                              |
| `work_permit.date_end` | date\|null      | วันหมดอายุ                            |
| `day90.date_start`     | date\|null      | วันเริ่มรอบรายงานตัว 90 วัน           |
| `day90.date_end`       | date\|null      | วันครบกำหนดรายงานตัว 90 วัน           |
| `tm_number`            | string\|null    | เลข TM.30                             |
| `note`                 | string\|null    | หมายเหตุ                              |
| `company`              | object\|null    | ข้อมูลบริษัทที่สังกัด                  |
| `agency`               | object\|null    | ข้อมูล agency                         |
| `created_at`           | datetime (ISO)  |                                       |
| `updated_at`           | datetime (ISO)  |                                       |

---

## 4. ตัวอย่างการเรียกใช้

### cURL (Linux/macOS)

```bash
curl -H "X-API-KEY: YOUR_KEY" \
  "https://your-domain/api/v1/labours?search=สมชาย&per_page=50"
```

### cURL (Windows PowerShell)

```powershell
curl.exe -H "X-API-KEY: YOUR_KEY" `
  "http://127.0.0.1:5168/api/v1/labours?per_page=50"
```

### PowerShell (Invoke-RestMethod)

```powershell
$headers = @{ "X-API-KEY" = "YOUR_KEY" }
Invoke-RestMethod -Uri "http://127.0.0.1:5168/api/v1/labours?per_page=50" `
                  -Headers $headers
```

### JavaScript (fetch)

```js
const res = await fetch(
  "https://your-domain/api/v1/labours?per_page=50",
  { headers: { "X-API-KEY": "YOUR_KEY" } }
);
const json = await res.json();
console.log(json.data);
```

> ⚠️ อย่าใส่ API Key ใน frontend JavaScript ที่ public ให้ backend ของแอปอื่น
> เป็นคนถือคีย์และเรียก API นี้แทน

### PHP (Guzzle)

```php
use GuzzleHttp\Client;

$client = new Client(['base_uri' => 'https://your-domain']);
$res = $client->get('/api/v1/labours', [
    'headers' => ['X-API-KEY' => 'YOUR_KEY'],
    'query'   => ['per_page' => 50, 'search' => 'สมชาย'],
]);
$data = json_decode($res->getBody(), true);
```

### Python (requests)

```python
import requests

r = requests.get(
    "https://your-domain/api/v1/labours",
    headers={"X-API-KEY": "YOUR_KEY"},
    params={"per_page": 50},
)
print(r.json())
```

### Postman

1. New Request → GET → `http://127.0.0.1:5168/api/v1/labours`
2. Tab **Headers** → ใส่ `X-API-KEY` = `YOUR_KEY`
3. Tab **Params** → ใส่ key/value ที่ต้องการ filter

---

## 5. HTTP Status Codes

| Code | ความหมาย                                                                     |
| ---- | ---------------------------------------------------------------------------- |
| 200  | สำเร็จ                                                                       |
| 401  | API key ไม่ถูกต้องหรือไม่ได้ส่ง                                              |
| 404  | ไม่พบข้อมูล                                                                  |
| 429  | เรียกถี่เกินไป (throttle ของ Laravel default คือ 60 ครั้ง/นาที/ip)           |
| 500  | Server error เช่นยังไม่ได้ตั้ง `LABOUR_API_KEY` หรือฐานข้อมูลล่ม             |

---

## 6. CORS

ค่า default เปิดทุก origin สำหรับ path `api/*` (ดู [config/cors.php](config/cors.php))
ถ้าต้องการให้ปลอดภัยขึ้น แนะนำแก้ `allowed_origins` เป็นโดเมนเฉพาะของแอปที่
เรียกใช้ เช่น:

```php
'allowed_origins' => ['https://app1.example.com', 'https://app2.example.com'],
```

---

## 7. Best Practices สำหรับผู้เรียกใช้

- เก็บ API key ไว้ฝั่ง backend เท่านั้น อย่าฝังใน mobile/web frontend
- ใช้ HTTPS เสมอใน production
- ใช้ pagination (`per_page=100~200`) แทน `per_page=all` สำหรับ sync ข้อมูลจำนวนมาก
  (ลด memory และ timeout)
- Cache ผลลัพธ์ฝั่ง client เมื่อเหมาะสม เพื่อลด load บน server
- ตรวจสอบ field `updated_at` หากต้องการ sync เฉพาะที่เปลี่ยนแปลง
- จัดการ error ของ HTTP status 4xx/5xx ทุกกรณี

---

## 8. การเปลี่ยน API Key (Rotation)

เมื่อสงสัยว่าคีย์รั่ว:

1. สุ่มคีย์ใหม่:
   ```bash
   php -r "echo bin2hex(random_bytes(32));"
   ```
2. แก้ค่า `LABOUR_API_KEY` ใน `.env`
3. `php artisan config:clear`
4. Restart `php artisan serve` หรือ web server (PHP-FPM/Apache)
5. แจ้งคีย์ใหม่ให้ทุกแอปที่เรียกใช้

---

## 9. Changelog

| Version | Date       | Notes                                                                |
| ------- | ---------- | -------------------------------------------------------------------- |
| v1.0    | 2026-04-29 | Initial release: list / show / find by passport, API-key auth       |
