<?php
// ══════════════════════════════════════════════════════════════════
//  StudyCoach — reminnder.alfahmax.com
// ══════════════════════════════════════════════════════════════════
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/study.php';
require_once __DIR__ . '/includes/rewards.php';

startSession();

$page        = preg_replace('/[^a-z_]/', '', $_GET['page'] ?? 'dashboard');
$publicPages = ['login', 'register'];

if (!in_array($page, $publicPages) && !isLoggedIn()) {
    header('Location: index.php?page=login'); exit;
}
if (in_array($page, ['login','register']) && isLoggedIn()) {
    header('Location: index.php?page=dashboard'); exit;
}

// ── POST Handler ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/includes/mailer.php';

    $action = $_POST['action'] ?? '';
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash'] = ['type'=>'error','msg'=>'Security token mismatch.'];
        header('Location: index.php?page=' . $page); exit;
    }

    switch ($action) {

        case 'login':
            $r = loginUser(trim($_POST['email'] ?? ''), $_POST['password'] ?? '');
            if ($r['success']) { header('Location: index.php?page=dashboard'); exit; }
            $_SESSION['flash'] = ['type'=>'error','msg'=>$r['message']];
            break;

        case 'register':
            if (($_POST['password']??'') !== ($_POST['password2']??'')) {
                $_SESSION['flash'] = ['type'=>'error','msg'=>'Passwords do not match.']; break;
            }
            if (strlen($_POST['password']??'') < 8) {
                $_SESSION['flash'] = ['type'=>'error','msg'=>'Password must be at least 8 characters.']; break;
            }
            $r = registerUser(trim($_POST['name']??''), trim($_POST['email']??''), $_POST['password']);
            if ($r['success']) { loginUser($_POST['email'], $_POST['password']); header('Location: index.php?page=dashboard'); exit; }
            $_SESSION['flash'] = ['type'=>'error','msg'=>$r['message']];
            break;

        case 'log_study':
            requireLogin();
            $minutes = max(0, min(1440, (int)($_POST['minutes']??0)));
            $excuse  = trim($_POST['excuse']??'');
            logStudySession((int)$_SESSION['user_id'], $minutes, $excuse ?: null);
            $rewards = processRewards((int)$_SESSION['user_id'], $minutes);
            $_SESSION['rewards_popup'] = $rewards;
            $_SESSION['flash'] = ['type'=>'success','msg'=>"✅ Logged {$minutes} min — +{$rewards['xp_earned']} XP!"];
            break;

        case 'use_freeze':
            requireLogin();
            $r = useStreakFreeze((int)$_SESSION['user_id']);
            $_SESSION['flash'] = ['type'=>$r['success']?'success':'error','msg'=>$r['message']];
            break;

        case 'save_settings':
            requireLogin();
            $db = getDB(); $uid = (int)$_SESSION['user_id'];
            $db->prepare("UPDATE users SET daily_goal_minutes=?,wake_time=?,sleep_time=?,reminder_interval_minutes=?,tone=?,timezone=?,reminders_enabled=?,name=? WHERE id=?")
               ->execute([
                   max(30, (int)(floatval($_POST['daily_goal_hours']??8)*60)),
                   $_POST['wake_time']??'07:00',
                   $_POST['sleep_time']??'23:00',
                   max(15,(int)($_POST['interval']??60)),
                   in_array($_POST['tone']??'',['Friendly','Neutral','Strict','Brutal']) ? $_POST['tone'] : 'Brutal',
                   $_POST['timezone']??'UTC',
                   isset($_POST['reminders_enabled'])?1:0,
                   trim($_POST['name']??''),
                   $uid,
               ]);
            $_SESSION['user_name'] = trim($_POST['name']??'');
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Settings saved!'];
            break;

        case 'add_silence':
            requireLogin();
            $db = getDB();
            $db->prepare("INSERT INTO silence_periods (user_id,start_datetime,end_datetime,reason) VALUES (?,?,?,?)")
               ->execute([(int)$_SESSION['user_id'],$_POST['start'],$_POST['end'],trim($_POST['reason']??'')]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Silence period added.'];
            break;

        case 'delete_silence':
            requireLogin();
            $db = getDB();
            $db->prepare("DELETE FROM silence_periods WHERE id=? AND user_id=?")
               ->execute([(int)($_POST['silence_id']??0),(int)$_SESSION['user_id']]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Silence period removed.'];
            break;

        case 'change_password':
            requireLogin();
            $db   = getDB(); $uid = (int)$_SESSION['user_id'];
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE id=?"); $stmt->execute([$uid]);
            $hash = $stmt->fetchColumn();
            if (!password_verify($_POST['current_password']??'', $hash)) {
                $_SESSION['flash'] = ['type'=>'error','msg'=>'Current password incorrect.']; break;
            }
            if (($_POST['new_password']??'') !== ($_POST['new_password2']??'')) {
                $_SESSION['flash'] = ['type'=>'error','msg'=>'New passwords do not match.']; break;
            }
            $db->prepare("UPDATE users SET password_hash=? WHERE id=?")
               ->execute([password_hash($_POST['new_password'], PASSWORD_BCRYPT), $uid]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Password changed.'];
            break;

        case 'logout':
            logoutUser();
            break;
    }

    header('Location: index.php?page=' . $page); exit;
}

$flash       = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$rewardsPop  = $_SESSION['rewards_popup'] ?? null; unset($_SESSION['rewards_popup']);
$user        = isLoggedIn() ? getCurrentUser() : null;

// ── Auth Pages ────────────────────────────────────────────────────
if (in_array($page, $publicPages)) { ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>StudyCoach — <?= ucfirst($page) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Space+Mono:wght@400;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="auth-page">
  <div class="auth-card">
    <div class="auth-logo">STUDYCOACH</div>
    <div class="auth-tagline">NO EXCUSES. NO MERCY. JUST RESULTS.</div>
    <?php if ($flash): ?><div class="alert alert-<?= $flash['type'] ?>"><?= htmlspecialchars($flash['msg']) ?></div><?php endif; ?>
    <?php if ($page==='login'): $csrf=csrfToken(); ?>
    <form method="POST" action="index.php?page=login">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="login">
      <div class="form-group"><label>Email</label><input type="email" name="email" required autofocus placeholder="you@example.com"></div>
      <div class="form-group"><label>Password</label><input type="password" name="password" required placeholder="••••••••"></div>
      <button type="submit" class="btn btn-primary btn-block" style="margin-top:8px;">Sign In →</button>
    </form>
    <div class="auth-switch">No account? <a href="index.php?page=register">Sign up free</a></div>
    <?php else: $csrf=csrfToken(); ?>
    <form method="POST" action="index.php?page=register">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="register">
      <div class="form-group"><label>Full Name</label><input type="text" name="name" required autofocus placeholder="Your name"></div>
      <div class="form-group"><label>Email</label><input type="email" name="email" required placeholder="you@example.com"></div>
      <div class="grid-2">
        <div class="form-group"><label>Password</label><input type="password" name="password" required placeholder="Min 8 chars" minlength="8"></div>
        <div class="form-group"><label>Confirm</label><input type="password" name="password2" required placeholder="Repeat"></div>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Create Account →</button>
    </form>
    <div class="auth-switch">Have an account? <a href="index.php?page=login">Sign in</a></div>
    <?php endif; ?>
  </div>
</div>
</body></html>
<?php exit; } ?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>StudyCoach — <?= ucfirst($page) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Space+Mono:wght@400;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="app-layout">

<!-- ── Sidebar ─────────────────────────────────────────────────── -->
<aside class="sidebar">
  <div class="sidebar-brand">
    <div class="logo">STUDYCOACH</div>
    <div class="tagline">ACCOUNTABILITY SYSTEM</div>
  </div>
  <nav class="sidebar-nav">
    <?php
    $nav = ['dashboard'=>['📊','Dashboard'],'log'=>['✏️','Log Study'],'analytics'=>['📈','Analytics'],'rewards'=>['🏆','Rewards'],'excuses'=>['📝','Excuses'],'settings'=>['⚙️','Settings']];
    foreach ($nav as $p=>[$icon,$label]): ?>
    <a href="index.php?page=<?= $p ?>" class="nav-item <?= $page===$p?'active':'' ?>">
      <span class="icon"><?= $icon ?></span><?= $label ?>
    </a>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-footer">
    <?php
    $sr = getStreakRow((int)$user['id']);
    $li = xpProgressInLevel((int)($sr['total_xp']??0));
    $cs = (int)($sr['current_streak']??0);
    ?>
    <div class="user-info">
      <div class="user-avatar" style="position:relative;">
        <?= strtoupper(substr($user['name'],0,1)) ?>
        <div style="position:absolute;bottom:-4px;right:-4px;background:#e94560;color:#fff;border-radius:50%;width:18px;height:18px;font-size:9px;font-weight:700;display:flex;align-items:center;justify-content:center;border:2px solid var(--bg2);"><?= $li['level'] ?></div>
      </div>
      <div style="flex:1;min-width:0;">
        <div class="user-name"><?= htmlspecialchars($user['name']) ?></div>
        <div style="display:flex;align-items:center;gap:6px;margin-top:3px;">
          <div style="flex:1;height:4px;background:var(--bg3);border-radius:2px;overflow:hidden;">
            <div style="height:100%;width:<?= $li['pct'] ?>%;background:#e94560;border-radius:2px;"></div>
          </div>
          <span style="font-size:10px;color:#e94560;font-weight:700;">Lv<?= $li['level'] ?></span>
        </div>
        <?php if ($cs>0): ?><div style="font-size:10px;color:#ff6b00;margin-top:2px;">🔥 <?= $cs ?> day streak</div><?php endif; ?>
      </div>
    </div>
    <form method="POST" action="index.php?page=<?= $page ?>" style="margin-top:12px;">
      <input type="hidden" name="action" value="logout">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <button type="submit" class="btn btn-secondary btn-sm btn-block">Sign Out</button>
    </form>
  </div>
</aside>

<!-- ── Main Content ───────────────────────────────────────────── -->
<main class="main-content">
<?php if ($flash): ?><div class="alert alert-<?= $flash['type'] ?>"><?= htmlspecialchars($flash['msg']) ?></div><?php endif; ?>
<?php

// ════════════════════════════════════════════════════════════════════
//  PAGE: DASHBOARD
// ════════════════════════════════════════════════════════════════════
if ($page === 'dashboard'):
    $uid      = (int)$user['id'];
    $todayMin = getDailyProgress($uid);
    $goalMin  = (int)$user['daily_goal_minutes'];
    $pct      = $goalMin>0 ? min(100,(int)round($todayMin/$goalMin*100)) : 0;
    $streak   = getStreak($uid);
    $weekly   = getWeeklyData($uid);
    $monthly  = getMonthlyCompletion($uid);
    $esc      = getEscalationLevel($uid);
    $awake    = isUserAwake($user);
    $silenced = isInSilencePeriod($uid);
    $todayH   = round($todayMin/60,1);
    $goalH    = round($goalMin/60,1);
    $remH     = round(max(0,$goalMin-$todayMin)/60,1);
    $todayEx  = getTodayExcuse($uid);
    $lastEx   = getLastExcuse($uid);
    $escClr   = ['','#00c853','#ff9800','#e94560','#f44336'];
    $escLbl   = ['','🟢 On Track','⚡ Slipping','⚠️ Falling Behind','🚨 Brutal Mode'];
    $statusTxt= $silenced ? '🤫 Silenced' : ($awake ? '🟢 Reminders Active' : '😴 Sleep Hours');
?>
<div class="page-header">
  <div class="page-title">DASHBOARD</div>
  <div class="page-subtitle"><?= date('l, F j, Y') ?></div>
</div>

<div class="stats-grid">
  <div class="stat-card"><div class="stat-value"><?= $todayH ?>h</div><div class="stat-label">Studied Today</div><div class="stat-change <?= $pct>=100?'up':'down' ?>"><?= $pct ?>% of goal</div></div>
  <div class="stat-card green"><div class="stat-value"><?= $goalH ?>h</div><div class="stat-label">Daily Goal</div><div class="stat-change"><?= $remH ?>h left</div></div>
  <div class="stat-card orange"><div class="stat-value">🔥 <?= $streak['current_streak'] ?></div><div class="stat-label">Day Streak</div><div class="stat-change up">Best: <?= $streak['longest_streak'] ?> days</div></div>
  <div class="stat-card blue"><div class="stat-value"><?= $monthly['completed'] ?>/<?= $monthly['total_days'] ?></div><div class="stat-label">Days This Month</div><div class="stat-change <?= $monthly['completed']>=$monthly['total_days']*0.7?'up':'down' ?>"><?= $monthly['total_days']>0?round($monthly['completed']/$monthly['total_days']*100):0 ?>% completion</div></div>
</div>

<div class="card mb-24" style="padding:14px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
    <span style="font-size:13px;"><?= $statusTxt ?></span>
    <span style="color:<?= $escClr[$esc] ?>;font-weight:700;font-size:13px;"><?= $escLbl[$esc] ?></span>
  </div>
  <a href="index.php?page=log" class="btn btn-primary btn-sm">+ Log Study Session</a>
</div>

<div class="card mb-24">
  <div class="card-header"><div class="card-title">TODAY'S PROGRESS</div><span style="color:var(--muted);font-size:12px;font-family:var(--mono);"><?= date('H:i') ?></span></div>
  <div class="progress-wrap">
    <div class="progress-label"><span>0h</span><span><?= $goalH ?>h goal</span></div>
    <div class="progress-track" style="height:20px;">
      <div class="progress-fill <?= $pct>=100?'success':'' ?>" style="width:<?= $pct ?>%;"></div>
    </div>
    <div style="text-align:center;margin-top:12px;font-family:var(--mono);font-size:32px;color:<?= $pct>=100?'var(--success)':'var(--accent)' ?>;"><?= $todayH ?>h / <?= $goalH ?>h</div>
  </div>
  <?php if ($todayEx): ?><div class="alert alert-warning" style="margin-top:14px;">📝 Today's note: "<?= htmlspecialchars($todayEx) ?>"</div><?php endif; ?>
</div>

<div class="card mb-24">
  <div class="card-header"><div class="card-title">LAST 7 DAYS</div></div>
  <?php
  $weekData=[];
  for($i=6;$i>=0;$i--) { $d=date('Y-m-d',strtotime("-{$i} days")); $weekData[$d]=0; }
  foreach($weekly as $row) $weekData[$row['log_date']]=(int)$row['total_minutes'];
  $mx=max(array_values($weekData)?:[1]);
  ?>
  <div style="display:flex;align-items:flex-end;gap:8px;height:120px;padding:10px 0 0;">
    <?php foreach($weekData as $date=>$mins):
      $h=$mx>0?max(4,round($mins/$mx*100)):4;
      $p2=$goalMin>0?min(100,round($mins/$goalMin*100)):0;
      $c=$p2>=100?'var(--success)':($p2>=50?'var(--warning)':'var(--accent)');
    ?><div style="flex:1;display:flex;flex-direction:column;align-items:center;height:100%;gap:4px;">
      <div style="flex:1;display:flex;align-items:flex-end;width:100%;">
        <div class="chart-bar" style="width:100%;height:<?= max(4,$h) ?>%;background:<?= $c ?>;border-radius:4px 4px 0 0;"></div>
      </div>
      <div style="font-size:10px;color:var(--muted);font-family:var(--mono);"><?= date('D',strtotime($date))[0] ?></div>
      <div style="font-size:9px;color:var(--muted);"><?= round($mins/60,1) ?>h</div>
    </div><?php endforeach; ?>
  </div>
</div>

<?php if ($lastEx): ?>
<div class="card" style="border-color:var(--warning);">
  <div style="font-size:11px;color:var(--warning);text-transform:uppercase;letter-spacing:1px;font-family:var(--mono);margin-bottom:8px;">LAST EXCUSE ON RECORD</div>
  <p style="margin:0;font-style:italic;">"<?= htmlspecialchars($lastEx['excuse_text']) ?>"</p>
  <div style="font-size:11px;color:var(--muted);margin-top:6px;"><?= date('M j, Y g:ia',strtotime($lastEx['logged_at'])) ?></div>
</div>
<?php endif; ?>

<?php
// ════════════════════════════════════════════════════════════════════
//  PAGE: LOG
// ════════════════════════════════════════════════════════════════════
elseif ($page === 'log'):
    $uid      = (int)$user['id'];
    $todayMin = getDailyProgress($uid);
    $goalMin  = (int)$user['daily_goal_minutes'];
    $csrf     = csrfToken();
    $db       = getDB();
    $stmt     = $db->prepare("SELECT * FROM study_logs WHERE user_id=? AND log_date=? ORDER BY logged_at DESC");
    $stmt->execute([$uid, date('Y-m-d')]);
    $todayLogs = $stmt->fetchAll();
?>
<div class="page-header"><div class="page-title">LOG STUDY SESSION</div><div class="page-subtitle">Record every minute — excuses are saved and used against you</div></div>

<div class="log-widget">
  <h3>📚 How much did you study?</h3>
  <div style="color:var(--muted);font-size:13px;margin-bottom:16px;">
    Today: <strong style="color:var(--text);"><?= round($todayMin/60,1) ?>h</strong> of <strong style="color:var(--accent);"><?= round($goalMin/60,1) ?>h</strong> goal
    &nbsp;·&nbsp; <?= round(max(0,$goalMin-$todayMin)/60,1) ?>h remaining
  </div>
  <form method="POST" action="index.php?page=log">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="log_study">
    <div class="grid-2">
      <div class="form-group">
        <label>Minutes Studied</label>
        <input type="number" name="minutes" id="minutesInput" min="1" max="1440" value="60" required>
      </div>
      <div class="form-group">
        <label>Quick Presets</label>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <?php foreach([15,30,45,60,90,120] as $m): ?>
          <button type="button" class="btn btn-secondary btn-sm preset-btn" data-val="<?= $m ?>"><?= $m ?>m</button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="form-group">
      <label>Excuse / Reason <span style="color:var(--muted);font-weight:400;">(optional — will be saved and referenced in future reminders)</span></label>
      <textarea name="excuse" rows="3" placeholder="If you couldn't study, explain why. This will be thrown back at you next time..."></textarea>
    </div>
    <button type="submit" class="btn btn-primary">Log Session ✓</button>
  </form>
</div>

<?php if ($todayLogs): ?>
<div class="card">
  <div class="card-header"><div class="card-title">TODAY'S SESSIONS</div></div>
  <div class="table-wrap"><table>
    <tr><th>Time</th><th>Minutes</th><th>Note</th></tr>
    <?php foreach($todayLogs as $l): ?>
    <tr>
      <td class="text-mono"><?= date('H:i',strtotime($l['logged_at'])) ?></td>
      <td><strong><?= $l['minutes_studied'] ?> min</strong></td>
      <td style="color:var(--muted);font-size:12px;"><?= $l['excuse']?htmlspecialchars($l['excuse']):'—' ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</div>
<?php endif; ?>

<?php
// ════════════════════════════════════════════════════════════════════
//  PAGE: ANALYTICS
// ════════════════════════════════════════════════════════════════════
elseif ($page === 'analytics'):
    $uid     = (int)$user['id'];
    $db      = getDB();
    $goalMin = (int)$user['daily_goal_minutes'];
    $streak  = getStreak($uid);
    $monthly = getMonthlyCompletion($uid);
    $s = $db->prepare("SELECT COALESCE(SUM(minutes_studied),0) FROM study_logs WHERE user_id=?"); $s->execute([$uid]); $totalMin=(int)$s->fetchColumn();
    $s = $db->prepare("SELECT log_date,SUM(minutes_studied) as total FROM study_logs WHERE user_id=? GROUP BY log_date ORDER BY total DESC LIMIT 1"); $s->execute([$uid]); $bestDay=$s->fetch();
    $s = $db->prepare("SELECT COUNT(*) FROM reminder_log WHERE user_id=?"); $s->execute([$uid]); $totalRem=(int)$s->fetchColumn();
    $s = $db->prepare("SELECT log_date,SUM(minutes_studied) as total FROM study_logs WHERE user_id=? AND log_date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY) GROUP BY log_date"); $s->execute([$uid]); $monthly30=$s->fetchAll(PDO::FETCH_KEY_PAIR);
    $s = $db->prepare("SELECT log_date,SUM(minutes_studied) as total,GROUP_CONCAT(excuse ORDER BY logged_at SEPARATOR ' | ') as excuses FROM study_logs WHERE user_id=? GROUP BY log_date ORDER BY log_date DESC LIMIT 14"); $s->execute([$uid]); $recentRows=$s->fetchAll();
?>
<div class="page-header"><div class="page-title">ANALYTICS</div><div class="page-subtitle">Your performance history</div></div>
<div class="stats-grid">
  <div class="stat-card"><div class="stat-value"><?= round($totalMin/60,1) ?>h</div><div class="stat-label">Total Studied</div></div>
  <div class="stat-card green"><div class="stat-value">🔥 <?= $streak['current_streak'] ?></div><div class="stat-label">Current Streak</div><div class="stat-change up">Best: <?= $streak['longest_streak'] ?></div></div>
  <div class="stat-card orange"><div class="stat-value"><?= $monthly['completed'] ?></div><div class="stat-label">Goal Days (30d)</div><div class="stat-change"><?= $monthly['total_days']>0?round($monthly['completed']/$monthly['total_days']*100):0 ?>%</div></div>
  <div class="stat-card blue"><div class="stat-value"><?= $totalRem ?></div><div class="stat-label">Reminders Sent</div></div>
  <?php if($bestDay): ?><div class="stat-card"><div class="stat-value"><?= round($bestDay['total']/60,1) ?>h</div><div class="stat-label">Best Day</div><div class="stat-change up"><?= date('M j',strtotime($bestDay['log_date'])) ?></div></div><?php endif; ?>
</div>

<div class="card mb-24">
  <div class="card-header"><div class="card-title">30-DAY HEATMAP</div></div>
  <div style="display:grid;grid-template-columns:repeat(10,1fr);gap:6px;">
    <?php for($i=29;$i>=0;$i--):
      $d=date('Y-m-d',strtotime("-{$i} days"));
      $min=isset($monthly30[$d])?(int)$monthly30[$d]:0;
      $p=$goalMin>0?min(1,$min/$goalMin):0;
      $bg=$min===0?'var(--bg3)':($p>=1?'var(--success)':($p>=0.5?'var(--warning)':'var(--accent))'));
    ?><div title="<?= date('M j',strtotime($d)) ?>: <?= round($min/60,1) ?>h" style="aspect-ratio:1;background:<?= $bg ?>;border-radius:4px;opacity:<?= $min>0?1:0.3 ?>;cursor:default;"></div><?php endfor; ?>
  </div>
  <div style="display:flex;gap:16px;margin-top:12px;font-size:11px;color:var(--muted);">
    <span>Legend:</span>
    <span><span style="display:inline-block;width:12px;height:12px;background:var(--bg3);border-radius:2px;vertical-align:middle;margin-right:4px;"></span>None</span>
    <span><span style="display:inline-block;width:12px;height:12px;background:var(--accent);border-radius:2px;vertical-align:middle;margin-right:4px;"></span>&lt;50%</span>
    <span><span style="display:inline-block;width:12px;height:12px;background:var(--warning);border-radius:2px;vertical-align:middle;margin-right:4px;"></span>50-99%</span>
    <span><span style="display:inline-block;width:12px;height:12px;background:var(--success);border-radius:2px;vertical-align:middle;margin-right:4px;"></span>Goal ✓</span>
  </div>
</div>

<div class="card">
  <div class="card-header"><div class="card-title">RECENT SESSIONS</div></div>
  <div class="table-wrap"><table>
    <tr><th>Date</th><th>Hours</th><th>% of Goal</th><th>Notes</th></tr>
    <?php foreach($recentRows as $row):
      $p=round(($row['total']/$goalMin)*100);
      $c=$p>=100?'var(--success)':($p>=50?'var(--warning)':'var(--accent)');
    ?><tr>
      <td class="text-mono"><?= date('D M j',strtotime($row['log_date'])) ?></td>
      <td><?= round($row['total']/60,1) ?>h</td>
      <td><span style="color:<?= $c ?>;font-weight:700;"><?= $p ?>%</span></td>
      <td style="color:var(--muted);font-size:12px;"><?= $row['excuses']?htmlspecialchars(substr($row['excuses'],0,60)):'—' ?></td>
    </tr><?php endforeach; ?>
  </table></div>
</div>

<?php
// ════════════════════════════════════════════════════════════════════
//  PAGE: REWARDS
// ════════════════════════════════════════════════════════════════════
elseif ($page === 'rewards'):
    $uid    = (int)$user['id'];
    $db     = getDB();
    $sr     = getStreakRow($uid);
    $badges = getUserBadges($uid);
    $allDef = allBadges();
    $xpHist = getXpHistory($uid, 14);
    $li     = xpProgressInLevel((int)($sr['total_xp']??0));
    $curS   = (int)($sr['current_streak']??0);
    $lonS   = (int)($sr['longest_streak']??0);
    $frz    = (int)($sr['streak_freezes']??0);
    $league = getLeagueTitle($curS);
    $lcol   = getLeagueColor($curS);
    $earnedKeys = array_column($badges,'key');
    $s = $db->prepare("SELECT COALESCE(SUM(minutes_studied),0) FROM study_logs WHERE user_id=?"); $s->execute([$uid]); $totMin=(int)$s->fetchColumn();
    $s = $db->prepare("SELECT COUNT(*) FROM (SELECT log_date FROM study_logs WHERE user_id=? AND log_date>=DATE_SUB(CURDATE(),INTERVAL 6 DAY) GROUP BY log_date HAVING SUM(minutes_studied)>=?) t"); $s->execute([$uid,(int)$user['daily_goal_minutes']]); $perfWeek=(int)$s->fetchColumn();
    $xpChart=[];
    for($i=13;$i>=0;$i--) { $d=date('Y-m-d',strtotime("-{$i} days")); $xpChart[$d]=$xpHist[$d]??0; }
    $maxXp=max(array_values($xpChart)?:[1]);
?>
<div class="page-header"><div class="page-title">REWARDS & PROGRESS</div><div class="page-subtitle">Streaks · Badges · XP · Levels</div></div>

<!-- Hero row -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:16px;margin-bottom:28px;">

  <!-- Streak -->
  <div class="card" style="text-align:center;padding:28px;">
    <div style="font-size:56px;<?= $curS>0?'animation:flamePulse 2s infinite':'filter:grayscale(1)' ?>;">🔥</div>
    <div style="font-family:var(--display);font-size:48px;color:var(--accent);line-height:1;"><?= $curS ?></div>
    <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:1.5px;font-family:var(--mono);margin-top:4px;">Day Streak</div>
    <div style="display:inline-block;padding:4px 14px;border-radius:50px;font-size:12px;font-weight:700;background:<?= $lcol ?>20;color:<?= $lcol ?>;border:1px solid <?= $lcol ?>40;margin-top:10px;"><?= $league ?></div>
    <div style="font-size:11px;color:var(--muted);margin-top:8px;">Best: <?= $lonS ?> days</div>
    <?php if($frz>0): ?>
    <div style="display:flex;align-items:center;justify-content:center;gap:4px;margin-top:10px;flex-wrap:wrap;">
      <?php for($i=0;$i<min($frz,5);$i++): ?><span style="font-size:18px;">🧊</span><?php endfor; ?>
      <span style="font-size:11px;color:#00bcd4;"><?= $frz ?> freeze<?= $frz>1?'s':'' ?></span>
    </div>
    <form method="POST" action="index.php?page=rewards" style="margin-top:10px;">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="use_freeze">
      <button type="submit" class="btn btn-sm" style="background:#00bcd420;color:#00bcd4;border:1px solid #00bcd440;" onclick="return confirm('Use a freeze to protect missed yesterday?')">🧊 Use Freeze</button>
    </form>
    <?php endif; ?>
  </div>

  <!-- Level ring -->
  <div class="card" style="text-align:center;padding:28px;">
    <svg viewBox="0 0 100 100" style="width:90px;height:90px;transform:rotate(-90deg);">
      <circle cx="50" cy="50" r="42" fill="none" stroke="var(--bg3)" stroke-width="8"/>
      <circle cx="50" cy="50" r="42" fill="none" stroke="#e94560" stroke-width="8"
              stroke-dasharray="<?= round($li['pct']*2.639) ?> 264"
              stroke-dashoffset="0" stroke-linecap="round"/>
      <text x="50" y="46" text-anchor="middle" fill="white" font-size="18" font-weight="bold" font-family="'Bebas Neue',cursive" transform="rotate(90,50,50)"><?= $li['level'] ?></text>
      <text x="50" y="60" text-anchor="middle" fill="#666" font-size="9" font-family="sans-serif" transform="rotate(90,50,50)">LEVEL</text>
    </svg>
    <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:1.5px;font-family:var(--mono);margin-top:8px;">XP Level</div>
    <div style="width:100%;height:6px;background:var(--bg3);border-radius:3px;overflow:hidden;margin:8px 0;">
      <div style="height:100%;width:<?= $li['pct'] ?>%;background:#e94560;border-radius:3px;"></div>
    </div>
    <div style="font-size:12px;color:var(--muted);"><?= number_format($li['current']) ?> / <?= number_format($li['needed']) ?> XP</div>
    <div style="font-size:11px;color:var(--accent);margin-top:4px;font-weight:700;">Total: <?= number_format($li['total_xp']) ?> XP</div>
  </div>

  <!-- This week -->
  <div class="card" style="text-align:center;padding:28px;">
    <div style="display:flex;gap:6px;justify-content:center;">
      <?php for($i=6;$i>=0;$i--):
        $d=date('Y-m-d',strtotime("-{$i} days"));
        $s2=$db->prepare("SELECT COALESCE(SUM(minutes_studied),0) FROM study_logs WHERE user_id=? AND log_date=?"); $s2->execute([$uid,$d]); $dm=(int)$s2->fetchColumn();
        $done=$dm>=(int)$user['daily_goal_minutes']&&$user['daily_goal_minutes']>0;
        $part=$dm>0&&!$done; $tod=$d===date('Y-m-d');
      ?><div style="display:flex;flex-direction:column;align-items:center;gap:4px;">
        <div style="width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;border:2px solid <?= $done?'var(--success)':($part?'var(--warning)':'var(--border)') ?>;background:<?= $done?'var(--success)':($part?'transparent':'transparent') ?>;color:<?= $done?'white':($part?'var(--warning)':'var(--muted)') ?>;<?= $tod?'box-shadow:0 0 0 2px var(--accent);':'' ?>"><?= $done?'✓':date('D',strtotime($d))[0] ?></div>
        <div style="font-size:9px;color:var(--muted);font-family:var(--mono);"><?= date('D',strtotime($d))[0] ?></div>
      </div><?php endfor; ?>
    </div>
    <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:1.5px;font-family:var(--mono);margin-top:12px;">This Week</div>
    <div style="font-family:var(--display);font-size:32px;color:<?= $perfWeek>=7?'var(--success)':'var(--accent)' ?>;"><?= $perfWeek ?><span style="font-size:16px;color:var(--muted);"> / 7</span></div>
    <?php if($perfWeek>=7): ?><div style="color:var(--success);font-size:13px;font-weight:600;">🌟 Perfect Week!</div><?php endif; ?>
  </div>

  <!-- Totals -->
  <div class="card" style="padding:28px;">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
      <?php foreach([['🕐',round($totMin/60).'h','Total Hours'],['🏅',count($badges),'Badges'],['🧊',$frz,'Freezes'],['🏆',$lonS.'d','Best Streak']] as [$ic,$v,$l]): ?>
      <div style="text-align:center;">
        <div style="font-size:20px;"><?= $ic ?></div>
        <div style="font-family:var(--display);font-size:28px;color:var(--accent);line-height:1;"><?= $v ?></div>
        <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-top:2px;"><?= $l ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- XP History -->
<div class="card mb-24">
  <div class="card-header"><div class="card-title">XP EARNED — LAST 14 DAYS</div></div>
  <div style="display:flex;align-items:flex-end;gap:6px;height:130px;padding:10px 0 0;">
    <?php foreach($xpChart as $date=>$xp):
      $h=$maxXp>0?max(4,round($xp/$maxXp*100)):4;
      $isTod=$date===date('Y-m-d');
    ?><div style="flex:1;display:flex;flex-direction:column;align-items:center;height:100%;gap:4px;">
      <div style="font-size:9px;color:var(--muted);min-height:14px;font-family:var(--mono);"><?= $xp>0?$xp:'' ?></div>
      <div style="flex:1;display:flex;align-items:flex-end;width:100%;">
        <div style="width:100%;height:<?= $h ?>%;background:<?= $xp>0?($isTod?'var(--success)':'var(--accent)'):'var(--bg3)' ?>;border-radius:4px 4px 0 0;<?= $isTod?'box-shadow:0 0 8px var(--success);':'' ?>" title="<?= date('M j',strtotime($date)) ?>: <?= $xp ?> XP"></div>
      </div>
      <div style="font-size:9px;color:var(--muted);font-family:var(--mono);"><?= date('j',strtotime($date)) ?></div>
    </div><?php endforeach; ?>
  </div>
</div>

<!-- Badges -->
<div class="card mb-24">
  <div class="card-header"><div class="card-title">BADGES</div><span style="color:var(--muted);font-size:13px;"><?= count($badges) ?> / <?= count($allDef) ?> earned</span></div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:12px;">
    <?php foreach($allDef as $key=>$def):
      $earned=in_array($key,$earnedKeys);
      $ea=array_values(array_filter($badges,fn($b)=>$b['key']===$key))[0]??null;
    ?><div style="text-align:center;padding:14px 8px;border-radius:10px;border:1px solid var(--border);background:<?= $earned?'var(--bg2)':'var(--bg3)' ?>;opacity:<?= $earned?1:0.4 ?>;transition:all 0.2s;<?= $earned?'cursor:default;':'cursor:not-allowed;' ?>" title="<?= htmlspecialchars($def['desc']) ?>">
      <div style="width:46px;height:46px;border-radius:50%;border:2px solid <?= $earned?$def['color']:'var(--border)' ?>;background:<?= $earned?$def['color'].'20':'transparent' ?>;display:flex;align-items:center;justify-content:center;font-size:22px;margin:0 auto 8px;"><?= $earned?$def['icon']:'🔒' ?></div>
      <div style="font-size:11px;font-weight:700;color:var(--text);"><?= htmlspecialchars($def['name']) ?></div>
      <div style="font-size:10px;color:var(--muted);margin-top:2px;line-height:1.3;"><?= htmlspecialchars($def['desc']) ?></div>
      <?php if($earned&&$ea): ?><div style="font-size:10px;color:var(--accent);margin-top:4px;font-family:var(--mono);"><?= date('M j',strtotime($ea['earned_at'])) ?></div><?php endif; ?>
    </div><?php endforeach; ?>
  </div>
</div>

<!-- Streak Roadmap -->
<div class="card">
  <div class="card-header"><div class="card-title">STREAK ROADMAP</div></div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:16px;">
    <?php foreach([[3,'🔥','On Fire','#ff6b00'],[7,'⚡','Week Warrior','#ffc107'],[14,'💪','Fortnight','#ff9800'],[30,'🏆','Monthly Master','#e94560'],[60,'💎','Diamond','#00bcd4'],[100,'👑','Centurion','#9c27b0']] as [$days,$ic,$lbl,$col]):
      $reached=$curS>=$days;
      $pct=min(100,round($curS/$days*100));
    ?><div style="text-align:center;padding:16px;border-radius:10px;border:1px solid <?= $reached?$col:'var(--border)' ?>;background:<?= $reached?$col.'10':'var(--bg3)' ?>;">
      <div style="width:52px;height:52px;border-radius:50%;border:2px solid <?= $reached?$col:'var(--border)' ?>;background:<?= $reached?$col.'20':'transparent' ?>;display:flex;align-items:center;justify-content:center;font-size:24px;margin:0 auto 8px;"><?= $reached?$ic:'🔒' ?></div>
      <div style="font-size:12px;font-weight:700;"><?= $lbl ?></div>
      <div style="font-size:11px;color:<?= $reached?$col:'var(--muted)' ?>;font-family:var(--mono);margin-top:2px;"><?= $days ?> days</div>
      <?php if(!$reached): ?>
      <div style="margin-top:8px;">
        <div style="height:3px;background:var(--bg);border-radius:3px;overflow:hidden;"><div style="height:100%;width:<?= $pct ?>%;background:<?= $col ?>;border-radius:3px;"></div></div>
        <div style="font-size:10px;color:var(--muted);margin-top:3px;"><?= $days-$curS ?> days to go</div>
      </div>
      <?php else: ?><div style="font-size:11px;color:<?= $col ?>;margin-top:4px;font-weight:700;">✓ Achieved</div><?php endif; ?>
    </div><?php endforeach; ?>
  </div>
</div>

<?php
// ════════════════════════════════════════════════════════════════════
//  PAGE: EXCUSES
// ════════════════════════════════════════════════════════════════════
elseif ($page === 'excuses'):
    $uid  = (int)$user['id'];
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM excuses WHERE user_id=? ORDER BY logged_at DESC");
    $stmt->execute([$uid]);
    $excuses = $stmt->fetchAll();
?>
<div class="page-header"><div class="page-title">EXCUSE LOG</div><div class="page-subtitle">Every excuse you've ever given — archived forever</div></div>
<?php if(empty($excuses)): ?>
<div class="card" style="text-align:center;padding:60px;">
  <div style="font-size:56px;margin-bottom:12px;">🎉</div>
  <div style="font-family:var(--display);font-size:32px;letter-spacing:2px;margin-bottom:8px;">NO EXCUSES ON RECORD</div>
  <div style="color:var(--muted);">Keep it that way. Don't give them ammunition.</div>
</div>
<?php else: ?>
<div class="card mb-24" style="border-color:var(--warning);padding:14px 20px;">
  <span style="color:var(--warning);font-size:13px;">⚠️ <?= count($excuses) ?> excuses saved. Every single one will be referenced in your future reminders.</span>
</div>
<div class="card">
  <div class="table-wrap"><table>
    <tr><th>#</th><th>Date & Time</th><th>Excuse</th></tr>
    <?php foreach($excuses as $i=>$e): ?>
    <tr>
      <td style="color:var(--muted);font-family:var(--mono);"><?= count($excuses)-$i ?></td>
      <td class="text-mono" style="white-space:nowrap;"><?= date('M j, Y H:i',strtotime($e['logged_at'])) ?></td>
      <td style="font-style:italic;">"<?= htmlspecialchars($e['excuse_text']) ?>"</td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</div>
<?php endif; ?>

<?php
// ════════════════════════════════════════════════════════════════════
//  PAGE: SETTINGS
// ════════════════════════════════════════════════════════════════════
elseif ($page === 'settings'):
    $uid      = (int)$user['id'];
    $db       = getDB();
    $csrf     = csrfToken();
    $timezones= DateTimeZone::listIdentifiers();
    $stmt     = $db->prepare("SELECT * FROM silence_periods WHERE user_id=? ORDER BY start_datetime DESC"); $stmt->execute([$uid]); $silences=$stmt->fetchAll();
    $tones    = ['Friendly','Neutral','Strict','Brutal'];
    $toneDesc = ['Friendly'=>'Gentle nudges','Neutral'=>'Factual reminders','Strict'=>'Pressure-driven','Brutal'=>'Unfiltered accountability'];
?>
<div class="page-header"><div class="page-title">SETTINGS</div><div class="page-subtitle">Configure your accountability system</div></div>

<div class="tabs">
  <a class="tab active" href="#" onclick="showTab('study',this);return false;">Study Goals</a>
  <a class="tab" href="#" onclick="showTab('schedule',this);return false;">Schedule</a>
  <a class="tab" href="#" onclick="showTab('reminders',this);return false;">Reminders</a>
  <a class="tab" href="#" onclick="showTab('silence',this);return false;">Silence</a>
  <a class="tab" href="#" onclick="showTab('account',this);return false;">Account</a>
</div>

<form method="POST" action="index.php?page=settings">
<input type="hidden" name="csrf_token" value="<?= $csrf ?>">
<input type="hidden" name="action" value="save_settings">

<div id="tab-study" class="card mb-24">
  <div class="card-header"><div class="card-title">STUDY GOALS</div></div>
  <div class="form-group">
    <label>Daily Study Goal (hours)</label>
    <input type="number" name="daily_goal_hours" min="0.5" max="24" step="0.5" value="<?= round($user['daily_goal_minutes']/60,1) ?>">
    <div style="color:var(--muted);font-size:12px;margin-top:6px;">Currently: <?= round($user['daily_goal_minutes']/60,1) ?>h/day = <?= $user['daily_goal_minutes'] ?> minutes</div>
  </div>
</div>

<div id="tab-schedule" class="card mb-24" style="display:none;">
  <div class="card-header"><div class="card-title">SLEEP SCHEDULE</div></div>
  <p style="color:var(--muted);font-size:13px;margin-bottom:20px;">No reminders will ever be sent during your sleep hours.</p>
  <div class="grid-2">
    <div class="form-group"><label>⏰ Wake-Up Time</label><input type="time" name="wake_time" value="<?= substr($user['wake_time'],0,5) ?>"></div>
    <div class="form-group"><label>😴 Bedtime</label><input type="time" name="sleep_time" value="<?= substr($user['sleep_time'],0,5) ?>"></div>
  </div>
  <div class="form-group">
    <label>Timezone</label>
    <select name="timezone">
      <?php foreach($timezones as $tz): ?><option value="<?= $tz ?>" <?= $user['timezone']===$tz?'selected':'' ?>><?= $tz ?></option><?php endforeach; ?>
    </select>
  </div>
</div>

<div id="tab-reminders" class="card mb-24" style="display:none;">
  <div class="card-header"><div class="card-title">REMINDER SETTINGS</div></div>
  <div class="form-group">
    <label>Reminder Interval (minutes)</label>
    <input type="number" name="interval" min="15" max="480" value="<?= $user['reminder_interval_minutes'] ?>">
    <div style="color:var(--muted);font-size:12px;margin-top:6px;">Interval shortens automatically when you procrastinate. Minimum 15 min.</div>
  </div>
  <div class="form-group">
    <label>Reminder Tone</label>
    <div class="tone-options">
      <?php foreach($tones as $t): ?>
      <div>
        <input type="radio" name="tone" value="<?= $t ?>" id="tone_<?= $t ?>" <?= $user['tone']===$t?'checked':'' ?> style="display:none;">
        <label for="tone_<?= $t ?>" class="tone-btn <?= $user['tone']===$t?'active':'' ?>" onclick="selectTone(this)" style="cursor:pointer;display:block;"><?= $t ?></label>
        <div style="font-size:11px;color:var(--muted);margin-top:4px;text-align:center;"><?= $toneDesc[$t] ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="form-group" style="margin-top:16px;">
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
      <input type="checkbox" name="reminders_enabled" value="1" <?= $user['reminders_enabled']?'checked':'' ?> style="width:auto;">
      Enable email reminders
    </label>
  </div>
</div>

<div style="margin-bottom:24px;"><button type="submit" class="btn btn-primary">Save Settings ✓</button></div>
</form>

<div id="tab-silence" class="card mb-24" style="display:none;">
  <div class="card-header"><div class="card-title">SILENCE PERIODS</div></div>
  <p style="color:var(--muted);font-size:13px;margin-bottom:20px;">Legitimate breaks — no reminders sent during these windows. All silences are logged.</p>
  <form method="POST" action="index.php?page=settings" style="margin-bottom:24px;">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="add_silence">
    <div class="grid-2">
      <div class="form-group"><label>Start</label><input type="datetime-local" name="start" required></div>
      <div class="form-group"><label>End</label><input type="datetime-local" name="end" required></div>
    </div>
    <div class="form-group"><label>Reason</label><input type="text" name="reason" placeholder="e.g. Doctor appointment, Family event"></div>
    <button type="submit" class="btn btn-secondary">Add Silence Period</button>
  </form>
  <?php if($silences): ?>
  <div class="table-wrap"><table>
    <tr><th>Start</th><th>End</th><th>Reason</th><th></th></tr>
    <?php foreach($silences as $s): ?><tr>
      <td class="text-mono"><?= date('M j H:i',strtotime($s['start_datetime'])) ?></td>
      <td class="text-mono"><?= date('M j H:i',strtotime($s['end_datetime'])) ?></td>
      <td><?= htmlspecialchars($s['reason']?:'—') ?></td>
      <td><form method="POST" action="index.php?page=settings" style="display:inline;">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="delete_silence">
        <input type="hidden" name="silence_id" value="<?= $s['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Remove?')">✕</button>
      </form></td>
    </tr><?php endforeach; ?>
  </table></div>
  <?php else: ?><div style="color:var(--muted);font-size:13px;">No silence periods yet.</div><?php endif; ?>
</div>

<div id="tab-account" class="card" style="display:none;">
  <div class="card-header"><div class="card-title">ACCOUNT</div></div>
  <form method="POST" action="index.php?page=settings" style="margin-bottom:24px;">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="save_settings">
    <input type="hidden" name="daily_goal_hours" value="<?= round($user['daily_goal_minutes']/60,1) ?>">
    <input type="hidden" name="wake_time" value="<?= $user['wake_time'] ?>">
    <input type="hidden" name="sleep_time" value="<?= $user['sleep_time'] ?>">
    <input type="hidden" name="interval" value="<?= $user['reminder_interval_minutes'] ?>">
    <input type="hidden" name="tone" value="<?= $user['tone'] ?>">
    <input type="hidden" name="timezone" value="<?= $user['timezone'] ?>">
    <?php if($user['reminders_enabled']): ?><input type="hidden" name="reminders_enabled" value="1"><?php endif; ?>
    <div class="form-group"><label>Display Name</label><input type="text" name="name" value="<?= htmlspecialchars($user['name']) ?>" required></div>
    <button type="submit" class="btn btn-secondary">Update Name</button>
  </form>
  <hr>
  <h3 style="font-family:var(--display);font-size:20px;letter-spacing:1px;margin-bottom:16px;">CHANGE PASSWORD</h3>
  <form method="POST" action="index.php?page=settings">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="change_password">
    <div class="form-group"><label>Current Password</label><input type="password" name="current_password" required></div>
    <div class="grid-2">
      <div class="form-group"><label>New Password</label><input type="password" name="new_password" required minlength="8"></div>
      <div class="form-group"><label>Confirm New</label><input type="password" name="new_password2" required minlength="8"></div>
    </div>
    <button type="submit" class="btn btn-danger">Change Password</button>
  </form>
</div>

<script>
function showTab(name,el){
  document.querySelectorAll('[id^="tab-"]').forEach(e=>e.style.display='none');
  document.getElementById('tab-'+name).style.display='block';
  document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
  if(el) el.classList.add('active');
}
function selectTone(el){
  document.querySelectorAll('.tone-btn').forEach(b=>b.classList.remove('active'));
  el.classList.add('active');
}
</script>

<?php endif; // end page switch ?>
</main>
</div>

<!-- ── Celebration Popup ──────────────────────────────────────── -->
<?php if ($rewardsPop):
    $nb   = $rewardsPop['new_badges'] ?? [];
    $xpe  = $rewardsPop['xp_earned'] ?? 0;
    $gb   = $rewardsPop['goal_bonus'] ?? 0;
    $lif  = $rewardsPop['level_info'] ?? [];
?>
<div id="cel-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,0.82);z-index:9999;display:flex;align-items:center;justify-content:center;" onclick="this.remove()">
<div style="background:#12121f;border:1px solid #e94560;border-radius:18px;padding:36px;max-width:420px;width:90%;text-align:center;animation:popIn 0.4s cubic-bezier(0.34,1.56,0.64,1);position:relative;" onclick="event.stopPropagation()">
  <button onclick="document.getElementById('cel-overlay').remove()" style="position:absolute;top:12px;right:16px;background:none;border:none;color:#555;font-size:20px;cursor:pointer;line-height:1;">✕</button>
  <div style="font-size:54px;animation:spinBounce 0.6s ease;">⚡</div>
  <div style="font-family:'Bebas Neue',cursive;font-size:44px;letter-spacing:2px;color:#e94560;line-height:1;">+<?= $xpe ?> XP</div>
  <?php if($gb>0): ?>
  <div style="background:rgba(0,200,83,0.12);border:1px solid #00c853;border-radius:8px;padding:8px 16px;margin:10px 0;color:#00c853;font-size:13px;font-weight:600;">🎯 Daily Goal Completed! +<?= $gb ?> bonus XP</div>
  <?php endif; ?>
  <?php if(!empty($lif)): ?>
  <div style="margin:14px 0;background:#0a0a1a;border-radius:10px;padding:14px;">
    <div style="font-size:12px;color:#555;margin-bottom:6px;">Level <?= $lif['level'] ?> Progress</div>
    <div style="background:#080810;border-radius:50px;height:10px;overflow:hidden;"><div style="height:100%;width:<?= $lif['pct'] ?>%;background:#e94560;border-radius:50px;"></div></div>
    <div style="font-size:11px;color:#555;margin-top:6px;"><?= number_format($lif['current']) ?> / <?= number_format($lif['needed']) ?> XP to next level</div>
  </div>
  <?php endif; ?>
  <?php if(!empty($nb)): ?>
  <div style="margin-top:14px;">
    <div style="font-size:11px;color:#555;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;">🏅 New Badge<?= count($nb)>1?'s':'' ?> Unlocked!</div>
    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
      <?php foreach($nb as $b): ?>
      <div style="background:<?= $b['color'] ?>20;border:1px solid <?= $b['color'] ?>60;border-radius:10px;padding:12px;min-width:80px;">
        <div style="font-size:28px;"><?= $b['icon'] ?></div>
        <div style="font-size:11px;font-weight:700;color:#e8e8f0;margin-top:4px;"><?= htmlspecialchars($b['name']) ?></div>
        <div style="font-size:10px;color:#555;"><?= htmlspecialchars($b['desc']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
  <button onclick="document.getElementById('cel-overlay').remove()" style="margin-top:20px;width:100%;padding:13px;background:#e94560;color:white;border:none;border-radius:8px;font-size:15px;font-weight:800;cursor:pointer;letter-spacing:1px;">Let's Go! 🔥</button>
</div>
</div>
<style>
@keyframes popIn{from{transform:scale(0.4);opacity:0}to{transform:scale(1);opacity:1}}
@keyframes spinBounce{0%{transform:scale(0) rotate(-30deg)}60%{transform:scale(1.2) rotate(10deg)}100%{transform:scale(1) rotate(0)}}
@keyframes flamePulse{0%,100%{transform:scale(1)}50%{transform:scale(1.08)}}
</style>
<?php endif; ?>

<script src="assets/js/app.js"></script>
</body>
</html>
