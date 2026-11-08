<?php
declare(strict_types=1);
require __DIR__.'/../inc/bootstrap.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
  <meta name="theme-color" content="#061626">
  <title>Live Reporting | กรีฑากลุ่มโรงเรียนบางละมุง 1</title>
  <link rel="stylesheet" href="./live.css?v=20261109-a">
</head>
<body>
  <div class="wall">
    <header class="topbar">
      <div class="identity">
        <div class="badge-mark" aria-hidden="true">🏃</div>
        <div>
          <div class="eyebrow">BANGLAMUNG 1 • ATHLETICS</div>
          <h1>ศูนย์รายงานตัวนักกรีฑา <span>LIVE</span></h1>
          <p>กลุ่มโรงเรียนบางละมุง 1 · สถานะการรายงานตัว ณ จุดเช็กอิน</p>
        </div>
      </div>
      <div class="top-actions">
        <div id="connect" class="connection" role="status">● กำลังเชื่อมต่อ</div>
        <div id="liveClock" class="clock" aria-label="เวลาปัจจุบัน">--:--:--</div>
        <button type="button" id="fullScreen" class="outline-btn">⛶ เต็มจอ</button>
      </div>
    </header>

    <main class="dashboard">
      <section class="hero">
        <div class="hero-topline">
          <span class="live-led">● รายการที่กำลังเรียกรายงานตัว</span>
          <span id="fixtureHeat" class="heat-chip">รอเปิดรายการ</span>
        </div>
        <div id="fixtureTitle" class="fixture-name">รอ Manager เลือกรายการแข่งขัน</div>
        <div id="fixtureMeta" class="fixture-sub">เมื่อ Manager เปิดรายการ หน้าจอนี้จะแสดงสถานะโดยอัตโนมัติ</div>
        <div class="stats">
          <div class="stat"><small>ทั้งหมด</small><strong id="total">—</strong><span>ใบสมัคร</span></div>
          <div class="stat success"><small>รายงานตัวแล้ว</small><strong id="checked">—</strong><span>ใบสมัคร</span></div>
          <div class="stat waiting"><small>ยังไม่รายงานตัว</small><strong id="pending">—</strong><span>ใบสมัคร</span></div>
          <div class="progress-card">
            <div id="progressRing" class="progress-ring"><span id="progressText">0%</span></div>
            <div><small>ความคืบหน้า</small><strong>การรายงานตัว</strong><span>อัปเดตอัตโนมัติทุก 3 วินาที</span></div>
          </div>
        </div>
      </section>

      <div id="scanToast" class="scan-toast" hidden aria-live="polite" role="status"></div>

      <div class="content-grid">
        <section class="panel roster">
          <header class="panel-title"><div><span class="head-dot"></span><h2>สถานะการรายงานตัวรายโรงเรียน</h2></div><span id="rosterCount" class="muted">—</span></header>
          <div class="list-title"><span>โรงเรียน / รหัสใบสมัคร</span><span>สถานะ</span></div>
          <div id="roster" class="roster-list" aria-live="polite">
            <div class="empty">รอข้อมูลรายการแข่งขันจาก Manager</div>
          </div>
        </section>
        <aside class="aside-column">
          <section class="panel feed">
            <header class="panel-title"><div><span class="head-dot success-dot"></span><h2>เช็กอินล่าสุด</h2></div><span>สด</span></header>
            <div id="recent" class="recent-list"><div class="empty">ยังไม่มีการรายงานตัวในรายการนี้</div></div>
          </section>
          <section class="panel next-panel">
            <header class="panel-title"><div><span class="head-dot"></span><h2>รายการถัดไป</h2></div></header>
            <div id="upcoming" class="upcoming"><div class="empty">กำลังโหลดตารางแข่งขัน</div></div>
          </section>
        </aside>
      </div>
    </main>

    <footer class="footer">
      <span>ระบบบริหารจัดการแข่งขันกรีฑา · กลุ่มโรงเรียนบางละมุง 1</span>
      <span id="lastRefresh">กำลังโหลดข้อมูล...</span>
      <a href="../" rel="noopener">เข้าสู่ระบบจัดการ</a>
    </footer>
  </div>
  <script defer src="./live.js?v=20261109-a"></script>
</body>
</html>