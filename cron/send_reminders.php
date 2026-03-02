<?php
/**
 * StudyCoach — Reminder Cron Job
 * ─────────────────────────────────────────────────────
 * Hostinger hPanel → Advanced → Cron Jobs → Add:
 *   * /10 * * * *   php /home/u399484983/domains/reminnder.alfahmax.com/public_html/cron/send_reminders.php
 */
define('CRON_RUNNING', true);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/study.php';

$db    = getDB();
$users = $db->query("SELECT * FROM users WHERE reminders_enabled = 1")->fetchAll();

foreach ($users as $user) {
    try {
        processUserReminder($user, $db);
    } catch (Throwable $e) {
        error_log("StudyCoach cron error for user {$user['id']}: " . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────────

function processUserReminder(array $user, PDO $db): void {
    $userId = $user['id'];

    if (!isUserAwake($user)) return;
    if (isInSilencePeriod($userId)) return;

    $tz = $user['timezone'] ?? 'UTC';
    try { $dtz = new DateTimeZone($tz); } catch (Exception $e) { $dtz = new DateTimeZone('UTC'); }

    $now   = new DateTime('now', $dtz);
    $today = $now->format('Y-m-d');

    $wake            = new DateTime($today . ' ' . $user['wake_time'], $dtz);
    $minutesSinceWake = ($now->getTimestamp() - $wake->getTimestamp()) / 60;
    if ($minutesSinceWake < 40) return;

    $stmt = $db->prepare("SELECT sent_at FROM reminder_log WHERE user_id=? ORDER BY sent_at DESC LIMIT 1");
    $stmt->execute([$userId]);
    $lastSent = $stmt->fetchColumn();

    $interval   = (int)($user['reminder_interval_minutes'] ?? 60);
    $escalation = getEscalationLevel($userId);

    // Shorten interval when escalating
    if ($escalation >= 4) $interval = max(15, (int)($interval / 3));
    elseif ($escalation >= 3) $interval = max(20, (int)($interval / 2));

    if ($lastSent) {
        $minutesSinceLast = ($now->getTimestamp() - (new DateTime($lastSent))->getTimestamp()) / 60;
        if ($minutesSinceLast < $interval) return;
    }

    $todayMin    = getDailyProgress($userId);
    $goalMin     = (int)$user['daily_goal_minutes'];
    $remaining   = max(0, $goalMin - $todayMin);
    $lastExcuse  = getLastExcuse($userId);

    [$subject, $body] = buildReminderContent($user, $escalation, $todayMin, $remaining, $lastExcuse);

    $sent = sendMail($user['email'], $user['name'], $subject, $body);

    if ($sent) {
        $db->prepare("INSERT INTO reminder_log (user_id,reminder_type,escalation_level,email_subject,email_body) VALUES (?,?,?,?,?)")
           ->execute([$userId, 'scheduled', $escalation, $subject, $body]);
        echo "[" . date('H:i:s') . "] ✔ Sent to {$user['email']} (level {$escalation})\n";
    } else {
        echo "[" . date('H:i:s') . "] ✘ Failed for {$user['email']}\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────────

function buildReminderContent(array $user, int $level, int $studiedMin, int $remainMin, ?array $lastExcuse): array {
    $name      = htmlspecialchars($user['name']);
    $goalH     = round($user['daily_goal_minutes'] / 60, 1);
    $studiedH  = round($studiedMin / 60, 1);
    $remainH   = round($remainMin / 60, 1);
    $percent   = $user['daily_goal_minutes'] > 0
        ? min(100, (int)round($studiedMin / $user['daily_goal_minutes'] * 100))
        : 0;

    // User's chosen tone shifts the floor, but escalation can still push harder
    $tone      = $user['tone'] ?? 'Brutal';
    $toneFloor = match($tone) { 'Friendly' => 0, 'Neutral' => 0, 'Strict' => 1, 'Brutal' => 1, default => 0 };
    $effective = min(4, max($toneFloor + $level, $level));

    $msgs = [
        1 => [
            'subject'  => "⏰ {$name}, your study day has begun!",
            'headline' => "Good morning, {$name}. Time to start.",
            'para'     => "You set a goal of <strong>{$goalH} hours</strong> today. The clock is running. Open your books.",
        ],
        2 => [
            'subject'  => "📚 {$name} — you should be studying right now",
            'headline' => "Hey {$name}. Still waiting.",
            'para'     => "You've logged <strong>{$studiedH}h</strong>. You need <strong>{$remainH}h</strong> more. Stop stalling.",
        ],
        3 => [
            'subject'  => "⚠️ {$name}, you are falling behind — act NOW",
            'headline' => "⚠️ Warning: You are wasting your time.",
            'para'     => "Only <strong>{$studiedH}h</strong> done out of {$goalH}h. Every minute you delay is a minute you can't recover. <strong>Stop whatever you're doing and study.</strong>",
        ],
        4 => [
            'subject'  => "🚨 {$name} — you are actively sabotaging your future",
            'headline' => "🚨 This is embarrassing, {$name}.",
            'para'     => "You have studied <strong>{$studiedH} hours out of {$goalH}</strong> today. That is <strong>{$percent}%</strong>. You are choosing failure right now. Not because life is hard — because you are sitting there doing nothing. <strong>Get up. Open your books. No more delays. No more excuses.</strong>",
        ],
    ];

    $m = $msgs[min($effective, 4)];

    $excuseRef = '';
    if ($lastExcuse) {
        $excText  = htmlspecialchars($lastExcuse['excuse_text']);
        $excDate  = date('M j', strtotime($lastExcuse['logged_at']));
        $excuseRef = "<div class='excuse'>🗒️ <strong>On {$excDate} you said:</strong> \"{$excText}\" — Is that still your excuse today?</div>";
    }

    $filled   = min(100, $percent);
    $barColor = $percent >= 100 ? '#00c853' : ($percent >= 50 ? '#ff9800' : '#e94560');

    $body = "
        <h2>{$m['headline']}</h2>
        <p>{$m['para']}</p>
        <div class='prog'>
            <div class='prog-lbl'><span>Progress</span><span>{$percent}%</span></div>
            <div class='prog-track'>
                <div class='prog-fill' style='width:{$filled}%;background:{$barColor};'></div>
            </div>
        </div>
        <div class='stat'>
            <div class='n'>{$studiedH}h / {$goalH}h</div>
            <div class='l'>Studied today</div>
        </div>
        {$excuseRef}
        <p style='color:#555;font-size:13px;margin-top:20px;'>Log your session to stop these reminders for now.</p>
    ";

    return [$m['subject'], $body];
}
