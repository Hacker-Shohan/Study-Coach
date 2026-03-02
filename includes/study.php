<?php
require_once __DIR__ . '/db.php';

// ─── Logging ──────────────────────────────────────────────────────────────────

function logStudySession(int $userId, int $minutes, ?string $excuse = null): void {
    $db    = getDB();
    $today = date('Y-m-d');

    $stmt = $db->prepare("SELECT id FROM study_logs WHERE user_id=? AND log_date=? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId, $today]);
    $existing = $stmt->fetch();

    if ($existing) {
        $db->prepare("UPDATE study_logs SET minutes_studied=minutes_studied+?, excuse=? WHERE id=?")
           ->execute([$minutes, $excuse, $existing['id']]);
    } else {
        $db->prepare("INSERT INTO study_logs (user_id,log_date,minutes_studied,excuse) VALUES (?,?,?,?)")
           ->execute([$userId, $today, $minutes, $excuse]);
    }

    if (!empty($excuse)) {
        $db->prepare("INSERT INTO excuses (user_id,excuse_text) VALUES (?,?)")
           ->execute([$userId, $excuse]);
    }

    updateStreak($userId);
}

// ─── Streak ───────────────────────────────────────────────────────────────────

function updateStreak(int $userId): void {
    $db = getDB();
    $u  = $db->prepare("SELECT daily_goal_minutes FROM users WHERE id=?");
    $u->execute([$userId]);
    $goal = (int)$u->fetchColumn();

    $today     = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    $s = $db->prepare("SELECT SUM(minutes_studied) FROM study_logs WHERE user_id=? AND log_date=?");
    $s->execute([$userId, $today]);
    $todayMin = (int)$s->fetchColumn();
    if ($todayMin < $goal) return;

    $row = getStreakRow($userId);
    if (!$row) {
        $db->prepare("INSERT INTO streaks (user_id,current_streak,longest_streak,last_active_date) VALUES (?,1,1,?)")
           ->execute([$userId, $today]);
        return;
    }

    if ($row['last_active_date'] === $today) return;

    $current = ($row['last_active_date'] === $yesterday) ? $row['current_streak'] + 1 : 1;
    $longest = max($row['longest_streak'], $current);

    $db->prepare("UPDATE streaks SET current_streak=?,longest_streak=?,last_active_date=? WHERE user_id=?")
       ->execute([$current, $longest, $today, $userId]);
}

function getStreak(int $userId): array {
    return getStreakRow($userId) ?? ['current_streak'=>0,'longest_streak'=>0,'last_active_date'=>null,'total_xp'=>0,'level'=>1,'streak_freezes'=>0];
}

// ─── Analytics ────────────────────────────────────────────────────────────────

function getDailyProgress(int $userId, ?string $date = null): int {
    $db   = getDB();
    $date = $date ?? date('Y-m-d');
    $stmt = $db->prepare("SELECT COALESCE(SUM(minutes_studied),0) FROM study_logs WHERE user_id=? AND log_date=?");
    $stmt->execute([$userId, $date]);
    return (int)$stmt->fetchColumn();
}

function getWeeklyData(int $userId): array {
    $db   = getDB();
    $stmt = $db->prepare("
        SELECT log_date, SUM(minutes_studied) AS total_minutes
        FROM study_logs
        WHERE user_id=? AND log_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        GROUP BY log_date ORDER BY log_date ASC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function getMonthlyCompletion(int $userId): array {
    $db   = getDB();
    $u    = $db->prepare("SELECT daily_goal_minutes FROM users WHERE id=?");
    $u->execute([$userId]);
    $goal = (int)$u->fetchColumn();

    $stmt = $db->prepare("
        SELECT log_date, SUM(minutes_studied) AS total
        FROM study_logs
        WHERE user_id=? AND log_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY log_date
    ");
    $stmt->execute([$userId]);
    $rows      = $stmt->fetchAll();
    $completed = count(array_filter($rows, fn($r) => (int)$r['total'] >= $goal));
    return ['completed' => $completed, 'total_days' => min((int)date('j'), 30), 'goal' => $goal];
}

function getLastExcuse(int $userId): ?array {
    $db   = getDB();
    $stmt = $db->prepare("SELECT excuse_text, logged_at FROM excuses WHERE user_id=? ORDER BY logged_at DESC LIMIT 1");
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function getTodayExcuse(int $userId): ?string {
    $db   = getDB();
    $stmt = $db->prepare("SELECT excuse FROM study_logs WHERE user_id=? AND log_date=? AND excuse IS NOT NULL ORDER BY logged_at DESC LIMIT 1");
    $stmt->execute([$userId, date('Y-m-d')]);
    $r = $stmt->fetchColumn();
    return $r ?: null;
}

function getEscalationLevel(int $userId): int {
    $db    = getDB();
    $today = date('Y-m-d');
    $stmt  = $db->prepare("SELECT COUNT(*) FROM reminder_log WHERE user_id=? AND DATE(sent_at)=?");
    $stmt->execute([$userId, $today]);
    $rc       = (int)$stmt->fetchColumn();
    $todayMin = getDailyProgress($userId);

    if ($todayMin === 0 && $rc >= 4) return 4;
    if ($todayMin === 0 && $rc >= 2) return 3;
    if ($todayMin  < 60 && $rc >= 1) return 2;
    return 1;
}

function isInSilencePeriod(int $userId): bool {
    $db   = getDB();
    $now  = date('Y-m-d H:i:s');
    $stmt = $db->prepare("SELECT id FROM silence_periods WHERE user_id=? AND start_datetime<=? AND end_datetime>=?");
    $stmt->execute([$userId, $now, $now]);
    return (bool)$stmt->fetch();
}

function isUserAwake(array $user): bool {
    $tz = $user['timezone'] ?? 'UTC';
    try { $dtz = new DateTimeZone($tz); } catch (Exception $e) { $dtz = new DateTimeZone('UTC'); }

    $nowTime = (new DateTime('now', $dtz))->format('H:i');
    $wake    = substr($user['wake_time'],  0, 5);
    $sleep   = substr($user['sleep_time'], 0, 5);

    if ($sleep < $wake) return $nowTime >= $wake || $nowTime < $sleep;
    return $nowTime >= $wake && $nowTime < $sleep;
}

// ─── Helper also used by rewards.php ─────────────────────────────────────────

function getStreakRow(int $userId): ?array {
    static $cache = [];
    if (isset($cache[$userId])) return $cache[$userId];
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM streaks WHERE user_id=?");
    $stmt->execute([$userId]);
    return $cache[$userId] = ($stmt->fetch() ?: null);
}
