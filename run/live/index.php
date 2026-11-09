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
  <meta name="theme-color" content="#06162c">
  <title>จอรายงานตัว LIVE | กรีฑากลุ่มโรงเรียนบางละมุง 1</title>
  <link rel="stylesheet" href="./live.css?v=20261109-results-marquee4">
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
    <section class="results-ticker" aria-label="ผลการแข่งขันล่าสุดที่บันทึกแล้ว">
      <div class="ticker-label"><span class="ticker-live-dot" aria-hidden="true"></span> 🏆 ผลการแข่งขันล่าสุด</div>
      <div class="ticker-window">
        <div class="ticker-track" id="latestResultTicker">
          <div class="ticker-sequence"><span class="ticker-placeholder">กำลังโหลดผลการแข่งขันล่าสุด...</span></div>
        </div>
      </div>
    </section>
    <section class="school-board" aria-label="สิบช่องโรงเรียนพร้อมภาพนักกรีฑาที่รายงานตัว">
      <div class="school-grid" id="schoolGrid">
        <div class="loading">กำลังโหลดข้อมูลการแข่งขัน...</div>
      </div>
    </section>
    <section class="up-next" aria-label="การรับรายงานตัวรายการถัดไป">
      <div class="next-icon" aria-hidden="true">⏭</div>
      <div class="next-description">
        <span class="next-eyebrow">NEXT UP <span class="next-accent">• รับรายงานตัวล่วงหน้า</span></span>
        <strong id="nextFixture">รอรายการแข่งขันถัดไป</strong>
        <span id="nextMeta">จุดรับรายงานตัวรายการต่อไป</span>
      </div>
      <div class="next-progress">
        <div class="next-progress-numbers"><span>รายงานตัวแล้ว</span><strong id="nextCount">—</strong></div>
        <div class="next-track"><div id="nextFill" class="next-fill"></div></div>
      </div>
    </section>
    <footer class="live-footer">
      <span class="footer-brand">ระบบบริหารจัดการกรีฑา · กลุ่มโรงเรียนบางละมุง 1</span>
      <span id="connection" role="status">● กำลังเชื่อมต่อ</span>
      <span id="lastRefresh">—</span>
      <button type="button" id="fullScreen" title="เปิดเต็มจอ">⛶ เต็มจอ</button>
    </footer>
  </main>
  <script defer src="./live.js?v=20261109-results-marquee4"></script>
</body>
</html>