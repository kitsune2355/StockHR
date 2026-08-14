# StockHL

ระบบสต็อกธุรการ Happyland — รับสินค้าเข้า เบิก จ่ายออก รายงาน
ฉลากบาร์โค้ด/QR และแจ้งเตือนทางอีเมล

เขียนด้วย PHP 5.2.6 / MySQL 5.0 บน AppServ (Apache 2.2)

---

## ติดตั้ง

### 1. วางโค้ดใน DocumentRoot

```bash
cd C:/AppServ/www
git clone https://github.com/kitsune2355/StockHL.git stock_hl
```

ชื่อโฟลเดอร์ต้องเป็น `stock_hl` เพราะลิงก์ในอีเมลกับใบพิมพ์อ้างอิงชื่อนี้

### 2. สร้างฐานข้อมูล

```bash
C:/AppServ/MySQL/bin/mysql.exe -u root -p -e "CREATE DATABASE stock_hl DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;"
```

### 3. ตั้งค่าการเชื่อมต่อ

```bash
cd C:/AppServ/www/stock_hl
cp config_ctrl/connect.example.php config_ctrl/connect.php
```

แล้วแก้ `$user_mysql` กับ `$password_mysql` ใน `config_ctrl/connect.php` ให้ตรงกับเครื่อง
ไฟล์นี้ถูกกันไว้ใน `.gitignore` เพราะมีรหัสผ่าน อย่าเอาขึ้น repo

### 4. นำเข้าโครงสร้างและข้อมูลตั้งต้น

รันไฟล์ใน `sql/` **ตามลำดับเลข** ข้ามไฟล์ที่ลงท้ายด้วย `_rollback`

```bash
cd C:/AppServ/www/stock_hl
for f in $(ls sql/*.sql | grep -v rollback | sort); do
  echo "-- $f"
  C:/AppServ/MySQL/bin/mysql.exe -u root -p1234 stock_hl < "$f" || break
done
```

PowerShell:

```powershell
cd C:\AppServ\www\stock_hl
Get-ChildItem sql\*.sql | Where-Object { $_.Name -notlike "*rollback*" } | Sort-Object Name | ForEach-Object {
    Write-Host "-- $($_.Name)"
    & C:\AppServ\MySQL\bin\mysql.exe -u root -p1234 stock_hl -e "source $($_.FullName)"
}
```

ต้องส่ง SQL เป็น **ไฟล์** เสมอ ไม่ใช่ `mysql -e "..."` ไม่งั้นภาษาไทยจะเพี้ยน

### 5. เปิดใช้งาน

เปิด AppServ (Apache + MySQL) แล้วเข้า

```
http://localhost/stock_hl/
```

เข้าระบบครั้งแรกด้วย `admin` / `admin1234` แล้ว **เปลี่ยนรหัสผ่านทันที**

---

## ตั้งค่าเพิ่มเติม

### อีเมลแจ้งเตือน

ตั้งค่า SMTP ที่เมนู **อีเมลและคู่มือ › อีเมลแจ้งเตือน**
อีเมลทุกฉบับเข้าคิวก่อน แล้วให้ตัวส่งมาหยิบไป ตั้งให้วิ่งเองด้วย

```
schtasks /create /tn "StockHL Mail Worker" /sc minute /mo 1 ^
  /tr "C:\AppServ\php5\php.exe C:\AppServ\www\stock_hl\cron\mail_worker.php" /ru SYSTEM
```

### กล้องส่อง QR

ปุ่มเปิดกล้องในหน้าเอกสารต้องการ **https** เบราว์เซอร์ไม่ยอมเปิดกล้องบน `http://`
ระหว่างที่ยังไม่มี https ให้ใช้เครื่องยิงบาร์โค้ดแบบเสียบ USB หรือพิมพ์รหัสเอง

---

## โครงไฟล์

| โฟลเดอร์ | หน้าที่ |
|---|---|
| `pages/` | หน้าจอแต่ละหน้า (ถูก include เข้า `main.php`) |
| `api/` | endpoint รับ POST เป็น JSON — ไม่มี SQL อยู่ในนี้ |
| `backend/` | ตรรกะและ SQL ทั้งหมด |
| `includes/` | โครงหน้า เมนู และ router |
| `assets/` | CSS, JavaScript, รูป |
| `sql/` | migration เรียงตามเลข พร้อมไฟล์ย้อนกลับ |
| `cron/` | ตัวส่งอีเมลที่ค้างในคิว |

`includes/menu.php` เป็นแหล่งความจริงแหล่งเดียวของเมนูและ route
เพิ่มหน้าใหม่ = เพิ่มหนึ่งรายการที่นั่น แล้วสร้างไฟล์ตาม `file`
