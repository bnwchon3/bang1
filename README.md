# Banglamung 1 Athletics — PHP / MariaDB Migration

เว็บไซต์: https://www.bannawang.ac.th/bang1/run/

Repository นี้เป็น **public** จึงเก็บเฉพาะซอร์สโค้ด ไม่เก็บ Excel ต้นฉบับ, ข้อมูลนักเรียน, เลขบัตรประชาชน, รหัสผ่าน, คีย์ Google Drive หรือไฟล์นำเข้าฐานข้อมูล

## Deployment
ตั้งค่า Repository > Settings > Secrets and variables > Actions:

Secrets:
- `FTPS_HOST`: `bannawang.ac.th`
- `FTPS_PORT`: `2121` (ตรวจสอบให้ตรงบริการ FTPS ที่ใช้งาน)
- `FTPS_USER`: `bang1@bannawang.ac.th`
- `FTPS_PASSWORD`: รหัสของบัญชี FTP ข้างต้น
- `DB_HOST`: `localhost`
- `DB_NAME`: `bannawan_bang1`
- `DB_USER`: `bannawan_bang1`
- `DB_PASSWORD`: รหัสฐานข้อมูล MariaDB
- `DEPLOY_TOKEN`: สุ่มค่าลับยาวอย่างน้อย 32 ไบต์
- `DRIVE_BRIDGE_URL`: URL ของ Google Apps Script Web App สำหรับอัปโหลดไป Google Drive
- `DRIVE_BRIDGE_TOKEN`: โทเคนเดียวกับค่า Script Properties `BRIDGE_TOKEN` ใน Google Apps Script

Variables:
- `ENABLE_DEPLOY`: `true` เมื่อใส่ secrets ครบและพร้อม Deploy เท่านั้น
- `PUBLIC_BASE_URL`: `https://www.bannawang.ac.th/bang1/run`

FTPS account ตามภาพมี root เป็น `.../public_html/bang1/` จึง Deploy `run/` ไปยังโฟลเดอร์ `run/` แบบ **relative**; ห้ามใช้ full DirectAdmin path ซ้ำ

## Security
- ไฟล์ `.private/config.php` ถูกสร้างจาก GitHub Actions และตั้งให้ไม่อนุญาตการเข้าถึงจากเว็บ
- PHP ใช้ PDO prepared statements, server-side session และ password hashes
- รูปนักวิ่งและเอกสารยืนยันตัวตนคงอยู่บน Google Drive; การอัปโหลดใหม่จะทำผ่าน Apps Script bridge ที่ตรวจสอบ token และสิทธิ์ฝั่ง PHP
- ข้อมูลจาก `.xlsx` ให้ import ผ่านช่องทางส่วนตัวหลัง Deploy; ห้าม commit ไฟล์นำเข้าลง repository
- ข้อมูลใน Excel ที่ใช้สูตร Google Sheets (เช่น `#NAME?`) ต้องแปลงตรรกะก่อนจึงจะได้ค่าที่ถูกต้อง
- ไม่ถือว่า Deploy ผ่านจนกว่า GitHub Actions และการเชื่อมต่อฐานข้อมูลจริงจะสำเร็จ
