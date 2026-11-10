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
  <link rel="stylesheet" href="./live.css?v=20261110-crest-order21">
  <link rel="stylesheet" href="../school-branding.css?v=20261110-crest-order21">
</head>
<body>
  <main class="live-screen">
    <header class="event-number">
      <span id="eventHeading">รายการแข่งขันที่ </span><strong id="eventNumber">—</strong>
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
      <div class="next-icon" id="nextIcon" aria-hidden="true">⏭</div>
      <div class="next-description">
        <span class="next-eyebrow" id="nextEyebrow">NEXT UP <span class="next-accent" id="nextAccent">• รับรายงานตัวล่วงหน้า</span></span>
        <strong id="nextFixture">รอรายการแข่งขันถัดไป</strong>
        <span id="nextMeta">จุดรับรายงานตัวรายการต่อไป</span>
      </div>
      <div class="next-progress">
        <div class="next-progress-numbers"><span id="nextProgressTitle">รายงานตัวแล้ว</span><strong id="nextCount">—</strong></div>
        <div class="next-track"><div id="nextFill" class="next-fill"></div></div>
      </div>
    </section>
    <footer class="live-footer">
      <span class="footer-brand">ระบบบริหารจัดการกรีฑา · กลุ่มโรงเรียนบางละมุง 1</span>
      <span id="connection" role="status">● กำลังเชื่อมต่อ</span>
      <span id="lastRefresh">—</span>
      <button type="button" class="news-sound-toggle" id="newsSoundLive" title="เปิดเสียงประกาศผลเมื่อมีผลใหม่" aria-pressed="false">🔇 เสียงผล</button>
      <button type="button" id="fullScreen" title="เปิดเต็มจอ">⛶ เต็มจอ</button>
    </footer>
    <section id="sportShowcase" class="sport-showcase" hidden aria-label="กราฟิกแนะนำรายการแข่งขันกรีฑา">
      <div class="sport-atmosphere" aria-hidden="true">
        <span class="sport-light sport-light-one"></span>
        <span class="sport-light sport-light-two"></span>
        <span class="sport-track-lines"></span>
      </div>
      <header class="sport-topbar">
        <div class="sport-brand">
          <span class="sport-brand-emblem" aria-hidden="true">⚡</span>
          <span><strong>BANGLAMUNG 1 ATHLETICS</strong><small>กรีฑากลุ่มโรงเรียนบางละมุง 1</small></span>
        </div>
        <div class="sport-topword">THE GAME <span>IS ON.</span></div>
        <div class="sport-onair"><span class="sport-onair-dot"></span> SPORT LIVE <span id="sportSlideCounter" class="sport-counter">— / —</span></div>
      </header>
      <section class="sport-headline" aria-label="รายการแข่งขันปัจจุบัน">
        <div class="sport-headline-copy">
          <div class="sport-kicker"><span>NOW SHOWING</span><span class="sport-kicker-separator">/</span><span id="sportEventNumber">รายการที่ —</span></div>
          <h1 id="sportEventName">กำลังเตรียมรายการแข่งขัน</h1>
          <div class="sport-meta-row">
            <span id="sportRound" class="sport-round-pill">SPORT SHOWCASE</span>
            <span id="sportEventMeta">ภาพนักกรีฑาจากโรงเรียนที่เข้าร่วม</span>
          </div>
        </div>
        <div class="sport-headline-decoration" aria-hidden="true"><span>TRACK</span><strong>01</strong></div>
      </section>
      <section class="sport-stage" aria-label="ภาพนักกรีฑาและโปรแกรมการแข่งขัน">
        <article class="sport-hero">
          <div class="sport-hero-top"><span class="sport-small-label" id="sportHeroLabel">FEATURED ATHLETE</span><span id="sportHeroHeat" class="sport-hero-heat">ATHLETE</span></div>
          <div id="sportHeroPhotos" class="sport-hero-photos"><div class="sport-image-placeholder">🏃</div></div>
          <div class="sport-hero-caption">
            <span class="sport-small-label" id="sportHeroCaptionLabel">ตัวแทนนักกรีฑาปัจจุบัน</span>
            <div class="sport-hero-school-heading">
              <span id="sportHeroFlag" class="sport-hero-flag-slot"></span>
              <h2 id="sportHeroSchool">กำลังโหลดข้อมูลโรงเรียน</h2>
            </div>
            <p id="sportHeroName">—</p>
          </div>
          <span class="sport-hero-number" aria-hidden="true">01</span>
        </article>
        <section class="sport-roster" aria-label="โรงเรียนที่เข้าร่วมรายการแข่งขัน">
          <div class="sport-roster-heading">
            <span><b class="sport-cyan-dot"></b> ATHLETE LINEUP <span id="sportTeamCount" class="sport-roster-count">10 TEAMS</span></span>
            <div id="sportRosterPager" class="sport-roster-pager" hidden aria-label="เปลี่ยนชุดภาพนักกีฬา">
              <button id="sportRosterPrev" type="button" aria-label="ชมชุดก่อนหน้า">‹</button>
              <span id="sportRosterPage">1 / 1</span>
              <button id="sportRosterNext" type="button" aria-label="ชมชุดถัดไป">›</button>
            </div>
          </div>
          <div id="sportRivalGrid" class="sport-rival-grid"><div class="sport-loading">กำลังโหลดภาพนักกรีฑาจริง...</div></div>
        </section>
        <aside class="sport-schedule" aria-label="โปรแกรมการแข่งขัน">
          <div class="sport-schedule-title"><span class="sport-schedule-icon">🏁</span><div><small>RACE LINEUP</small><h2>รายการแข่งขันวันนี้</h2></div></div>
          <div id="sportScheduleList" class="sport-schedule-list"><div class="sport-schedule-empty">รอรายการแข่งขัน</div></div>
          <div class="sport-schedule-summary">
            <span>รายการทั้งหมด</span>
            <strong id="sportEventTotal">—</strong>
            <small>เรียงตามตารางแข่งขัน</small>
          </div>
        </aside>
      </section>
      <footer class="sport-lower-third">
        <div class="sport-next-copy"><span class="sport-next-chevron">⏭</span><div><small>UP NEXT • รายการแข่งขันถัดไป</small><strong id="sportNextEvent">กำลังโหลดรายการถัดไป</strong></div></div>
        <div class="sport-progress-box"><div class="sport-progress-label"><span>AUTO SHOWCASE</span><strong id="sportProgressTime">15s</strong></div><div class="sport-progress-track"><div id="sportProgressFill"></div></div></div>
        <button type="button" class="news-sound-toggle news-sound-sport" id="newsSoundSport" title="เปิดเสียงผลการแข่งขัน" aria-pressed="false">🔇 เสียง</button>
        <button id="sportFullScreen" type="button" class="sport-fullscreen" aria-label="เปิดหรือปิดเต็มจอ" title="เปิดเต็มจอ">⛶</button>
      </footer>
      <div id="sportIntro" class="sport-intro" aria-hidden="true">
        <div class="sport-intro-slash sport-intro-slash-one"></div>
        <div class="sport-intro-slash sport-intro-slash-two"></div>
        <div class="sport-intro-inner">
          <span class="sport-intro-eyebrow">🏁 BANGLAMUNG ATHLETICS • SPORT INTRO</span>
          <span id="sportIntroNumber" class="sport-intro-number">EVENT —</span>
          <strong id="sportIntroTitle" class="sport-intro-title">READY TO RUN</strong>
          <span class="sport-intro-caption">ON YOUR MARKS. GET SET. GO!</span>
        </div>
      </div>
    </section>
    <!-- Shared full-screen news interruption; works in Check-in and Sport modes. -->
    <section id="raceBreaking" class="race-breaking" hidden aria-label="ประกาศผลการแข่งขันล่าสุด" role="region">
      <div class="race-breaking-atmosphere" aria-hidden="true">
        <div class="race-breaking-ring ring-one"></div>
        <div class="race-breaking-ring ring-two"></div>
        <div class="race-breaking-sprint-lines"></div>
      </div>
      <div id="breakingConfetti" class="race-breaking-confetti" aria-hidden="true"></div>
      <header class="race-breaking-header">
        <div class="race-breaking-live"><span class="race-breaking-dot"></span> LIVE • BREAKING RESULTS</div>
        <span id="breakingLiveStatus" class="race-breaking-status">ผลการแข่งขันล่าสุด</span>
        <button type="button" class="news-sound-toggle race-breaking-audio" id="newsSoundBreaking" title="เปิด/ปิดทำนองเฉลิมฉลอง" aria-pressed="false">🔇 เปิดเสียง</button>
      </header>
      <div class="race-breaking-main">
        <div class="race-breaking-kicker">BANGLAMUNG 1 • ATHLETICS</div>
        <div id="breakingTitle" class="race-breaking-title">⚡ TRACK FLASH</div>
        <div id="breakingSubtitle" class="race-breaking-subtitle">ผลการแข่งขันล่าสุด</div>
        <h1 id="breakingEvent" class="race-breaking-event">กำลังโหลดผลการแข่งขัน</h1>
        <div id="breakingRound" class="race-breaking-round">—</div>
        <div id="breakingWinners" class="race-breaking-winners"></div>
        <div id="breakingMessage" class="race-breaking-message"></div>
      </div>
      <footer class="race-breaking-footer">
        <div class="race-breaking-footer-copy"><span>⚡</span><strong id="breakingFooterText">รายงานผลการแข่งขันอย่างเป็นทางการ</strong></div>
        <div class="race-breaking-progress"><div id="breakingProgressFill"></div></div>
        <div id="breakingRemaining" class="race-breaking-remaining">15</div>
      </footer>
    </section>
  </main>
  <script defer src="./live.js?v=20261110-school-overflow22"></script>
</body>
</html>