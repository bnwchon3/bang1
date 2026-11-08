# ขั้นตอนเปิดใช้งานจริง — Banglamung 1 Athletics

เป้าหมาย: https://www.bannawang.ac.th/bang1/run/

## 1. ตรวจค่าก่อน Deploy

ตั้งค่า **Repository secrets** ที่ GitHub Settings > Secrets and variables > Actions:

| Name | ตัวอย่าง/แหล่งที่มา |
|---|---|
| FTPS_HOST | bannawang.ac.th |
| FTPS_PORT | 2121 (ยืนยันกับโฮสต์) |
| FTPS_USER | bang1@bannawang.ac.th |
| FTPS_PASSWORD | รหัสผ่าน FTP |
| DB_HOST | localhost |
| DB_NAME | bannawan_bang1 |
| DB_USER | bannawan_bang1 |
| DB_PASSWORD | รหัสผ่าน MariaDB |
| DEPLOY_TOKEN | รหัสสุ่ม 32 ไบต์ หรือมากกว่า |
| DRIVE_BRIDGE_URL | URL Apps Script Web App ที่ลงท้าย /exec |
| DRIVE_BRIDGE_TOKEN | ต้องตรงกับ Script Property BRIDGE_TOKEN |

อย่าคัดลอกรหัสผ่านลง README, GitHub Issues, Actions logs หรือในช่องแชต

ตั้ง **Repository variables**:
- ENABLE_DEPLOY = true (เปิดหลังตรวจครบเท่านั้น)
- PUBLIC_BASE_URL = https://www.bannawang.ac.th/bang1/run

**หมายเหตุ:** GitHub ไม่แสดง Secret Values หลังบันทึก จึงยืนยันได้เพียงว่าผ่าน validation เมื่อ Workflow ทำงานจริง

## 2. เช็ก DirectAdmin

- บัญชี FTP ที่เลือก Custom ถูกจำกัด root ให้ตรงกับ `.../public_html/bang1/` (ดังนั้น Workflow อัปโหลดไป `run/` และ `.private/` ตรงนั้น)
- MariaDB `bannawan_bang1` ถูกสร้างแล้ว และผู้ใช้ `bannawan_bang1` มีสิทธิ์ CREATE TABLE/INSERT/UPDATE/SELECT/DELETE
- เลือก PHP **8.2 หรือ 8.3** (รองรับ `mixed`, `never`, `str_contains`)
- เปิดส่วนขยาย PHP `pdo_mysql`, `curl`, `fileinfo`, `zlib` และ `openssl`
- เปิด HTTPS ที่ `www.bannawang.ac.th`; อย่าใช้ URL DirectAdmin port 4229 เป็น URL ของระบบ

## 3. Deploy

ไปที่ GitHub > Actions > Deploy Bang1 (FTPS) > Run workflow > เลือก `main`.

ต้องสำเร็จครบ:
1. Check mandatory configuration
2. Validate PHP
3. Build private server config
4. Install FTPS client
5. Secure upload to restricted FTP root /bang1/
6. Apply idempotent DB schema

ถ้าขึ้น **Skipped** ตรวจ `ENABLE_DEPLOY=true`.
ถ้า FTPS login/SSL fail ตรวจพอร์ต, รหัสผ่าน, เงื่อนไข TLS certificate ของ Host.
ถ้า 403 หลังติดตั้ง ตรวจ DEPLOY_TOKEN และการส่ง header ไปที่ /setup/migrate.php.
ถ้า 500 ตรวจ PHP version, PDO_mysql, DB credentials, สิทธิ์ฐานข้อมูล และ server error log.

## 4. นำเข้า Excel ครั้งแรก (ส่วนตัว)

ไฟล์ `bang1_private_import.json.gz` **เป็นไฟล์ข้อมูลส่วนบุคคลห้าม commit ขึ้น GitHub สาธารณะ**.
เปิด `https://www.bannawang.ac.th/bang1/run/setup/import-ui.html`,
ใส่ค่า DEPLOY_TOKEN ส่วนตัวและเลือกไฟล์ แล้วกดนำเข้า.
ระบบบันทึกทั้งชีตต้นฉบับลง `source_rows` และตารางใช้งานเฉพาะที่แม็ปแล้ว.
ควรตรวจจำนวนรายการโรงเรียน/นักวิ่ง/ใบสมัคร/ผู้ฝึกซ้อมหลังนำเข้า.
ไฟล์ต้นฉบับมีบางค่าแบบ `#NAME?` และสูตรที่ใช้เฉพาะ Google Sheets; ต้องแปลงสูตรเหล่านี้และตรวจผลก่อนเปิดใช้งานจริง.

การอัปโหลดครั้งแรก **ต้องไม่ใช้ ZIP/XLSX ตรง ๆ** เพราะหน้า Import รับไฟล์ gzip JSONL ที่เตรียมไว้เท่านั้น.

## 5. Google Drive — ไฟล์คงอยู่เดิม

- Script Properties: `BRIDGE_TOKEN`, `PHOTO_FOLDER_ID`, `IDPROOF_FOLDER_ID`
- เว็บแอปทำงานในชื่อเจ้าของโฟลเดอร์ที่มีสิทธิ์
- `DRIVE_BRIDGE_URL` คือ URL web app ลงท้าย `/exec`
- ลองแนบ **รูปทดสอบ** 1 รูปและเปิดไฟล์ใน Drive เพื่อยืนยันการบันทึก

**ข้อควรทราบ:** Google Drive URL สำหรับดูไฟล์ `/file/d/ID/view` ไม่ใช่ URL ภาพตรง; ภาพใหม่อาจยังไม่แสดงใน `<img>` จนกว่าจะปรับวิธีแสดงภาพ. ไม่ควรเปิดแชร์บัตรประชาชนเป็นสาธารณะเพื่อแก้ปัญหานี้.

## 6. ความพร้อมจริง

Syntax check ผ่าน ≠ ระบบใช้งานจริงผ่าน. ต้องตรวจลงชื่อเข้าใช้, สิทธิ์ครู/แอดมิน, เพิ่มและแก้ไขนักวิ่ง, สมัครรายการ, สุ่มฮีท, พิมพ์ใบสมัคร, อัปโหลด Drive, การมองเห็นข้อมูลข้ามโรงเรียน และรายงานผล/เกียรติบัตรก่อนใช้งานกับข้อมูลจริง.

ตาราง `source_rows` เป็น archive ใน MariaDB ไม่ใช่หลักประกันว่าทุกฟังก์ชันของ Apps Script ถูกแปลงครบแล้ว.
