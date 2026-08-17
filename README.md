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

แล้วกรอกค่าใน `config_ctrl/connect.php` **ทั้งสองชุด** — ชุดเครื่องพัฒนากับชุดเซิร์ฟเวอร์จริง
ไฟล์เดียวใช้ได้ทั้งสองที่ เพราะมันดูสภาพแวดล้อมเองแล้วเลือกชุดที่ถูก

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

## นำขึ้นเซิร์ฟเวอร์จริง

`config_ctrl/connect.php` ตรวจเองว่ากำลังรันอยู่ที่ไหน แล้วเลือกค่าเชื่อมต่อให้ถูกชุด

| ที่ | เงื่อนไข |
|---|---|
| เครื่องพัฒนา | เปิดผ่าน `localhost` / `127.0.0.1` / `192.168.x.x` หรือรันบรรทัดคำสั่งบน Windows |
| เซิร์ฟเวอร์จริง | ชื่อโฮสต์อื่น หรือรันบรรทัดคำสั่งบน Linux |

จึงอัปโหลดทั้งโฟลเดอร์ทับได้เลย ไม่ต้องมาแก้ไฟล์นี้ทุกครั้งที่ deploy

### สิ่งที่ต้องทำครั้งเดียวบนเซิร์ฟเวอร์

1. **สร้างฐานข้อมูลของเซิร์ฟเวอร์** แล้วนำเข้าไฟล์ใน `sql/` ตามลำดับเลข
   (คนละฐานข้อมูลกับเครื่องพัฒนา ข้อมูลไม่ปนกัน)

2. **ตั้งที่อยู่เว็บ** ที่เมนู *อีเมลและคู่มือ › อีเมลแจ้งเตือน* ช่อง "ที่อยู่ระบบ"

   ```
   https://develophpg.net/stock_hl/
   ```

   ค่านี้เก็บอยู่ในฐานข้อมูล แต่ละที่จึงมีของตัวเอง ใช้สร้างปุ่มในอีเมล
   กับลิงก์เซ็นรับของ ถ้าไม่ตั้ง ลิงก์ในอีเมลจะกดไม่ได้

3. **ให้สิทธิ์เขียนโฟลเดอร์โลโก้** ไม่งั้นอัปโหลดโลโก้บริษัทไม่ได้

   ```bash
   chmod 775 assets/img/company
   ```

4. **ตั้งตัวส่งอีเมล** — Linux ใช้ crontab แทน Task Scheduler

   ```
   * * * * * /usr/bin/php /home/บัญชี/domains/develophpg.net/public_html/stock_hl/cron/mail_worker.php
   ```

   โฮสต์ที่ตั้ง cron ไม่ได้ ให้ตั้ง `cron_key` ใน `tb_app_setting` แล้วเรียกผ่าน URL แทน

### ข้อดีของฝั่งเซิร์ฟเวอร์

เซิร์ฟเวอร์เป็น https อยู่แล้ว **ปุ่มเปิดกล้องส่อง QR ในหน้าเอกสารจึงใช้ได้**
ต่างจากเครื่องพัฒนาที่เป็น http ซึ่งเบราว์เซอร์ไม่ยอมให้เปิดกล้อง

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
