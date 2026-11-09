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
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#0505e9">
  <title>จอรายงานตัว LIVE | กรีฑากลุ่มโรงเรียนบางละมุง 1</title>
  <link rel="stylesheet" href="./live.css?v=20261109-ten-schools1">
</head>
<body>
  <main class="live-screen">
    <header class="event-number">
      <span>รายการแข่งขันที่ </span><strong id="eventNumber">—</strong>
    </header>
    <div class="event-banner">
      <h1 id="fixtureTitle">รอ Manager เลือกรายการแข่งขัน</h1>
      <div id="roundLabel" class="round-label">รอเปิดรายการ</div>
    </div>
    <div class="event-meta">
      <span id="fixtureMeta">กลุ่มโรงเรียนบางละมุง 1 · เช็กชื่อรายงานตัว</span>
      <span id="checkedCount">ยังไม่มีรายการ Active</span>
    </div>
    <section class="school-board" aria-label="แสดงรายชื่อและภาพนักกรีฑาที่รายงานตัวของสิบโรงเรียน">
      <div class="school-grid" id="schoolGrid">
        <div class="loading">กำลังโหลดข้อมูลการแข่งขัน...</div>
      </div>
    </section>
    <footer class="live-footer">
      <span class="footer-brand">ระบบบริหารจัดการกรีฑา · กลุ่มโรงเรียนบางละมุง 1</span>
      <span id="connection" role="status">● กำลังเชื่อมต่อ</span>
      <span id="lastRefresh">—</span>
      <button type="button" id="fullScreen" title="เปิดเต็มจอ">⛶ เต็มจอ</button>
    </footer>
  </main>
  <script defer src="./live.js?v=20261109-ten-schools1"></script>
</body>
</html>