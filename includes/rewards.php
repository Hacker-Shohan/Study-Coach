<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/study.php';

const XP_PER_MINUTE        = 1;
const XP_GOAL_BONUS        = 50;
const XP_STREAK_MULTIPLIER = 0.10;
const XP_FREEZE_EARN_EVERY = 7;

// ─── Levels ───────────────────────────────────────────────────────────────────

function xpForLevel(int $level): int {
    return (int)(100 * $level + 50 * $level * ($level - 1));
}

function levelFromXp(int $xp): int {
    $level = 1;
    while ($xp >= xpForLevel($level + 1)) $level++;
    return min($level, 50);
}

function xpProgressInLevel(int $totalXp): array {
    $level   = levelFromXp($totalXp);
    $start   = xpForLevel($level);
    $end     = xpForLevel($level + 1);
    $current = $totalXp - $start;
    $needed  = $end - $start;
    return [
        'level'    => $level,
        'current'  => $current,
        'needed'   => $needed,
        'pct'      => $needed > 0 ? min(100, (int)round($current / $needed * 100)) : 100,
        'total_xp' => $totalXp,
    ];
}

// ─── Badge Definitions ────────────────────────────────────────────────────────

function allBadges(): array {
    return [
        'streak_3'    => ['icon'=>'🔥','name'=>'On Fire',        'desc'=>'3-day streak',             'color'=>'#ff6b00'],
        'streak_7'    => ['icon'=>'⚡','name'=>'Week Warrior',    'desc'=>'7-day streak',             'color'=>'#ffc107'],
        'streak_14'   => ['icon'=>'💪','name'=>'Fortnight Force', 'desc'=>'14-day streak',            'color'=>'#ff9800'],
        'streak_30'   => ['icon'=>'🏆','name'=>'Monthly Master',  'desc'=>'30-day streak',            'color'=>'#e94560'],
        'streak_60'   => ['icon'=>'💎','name'=>'Diamond Grinder', 'desc'=>'60-day streak',            'color'=>'#00bcd4'],
        'streak_100'  => ['icon'=>'👑','name'=>'Centurion',       'desc'=>'100-day streak',           'color'=>'#9c27b0'],
        'hours_10'    => ['icon'=>'📖','name'=>'Getting Started', 'desc'=>'10 total hours logged',    'color'=>'#4caf50'],
        'hours_50'    => ['icon'=>'📚','name'=>'Bookworm',        'desc'=>'50 total hours logged',    'color'=>'#2196f3'],
        'hours_100'   => ['icon'=>'🎓','name'=>'Scholar',         'desc'=>'100 hours logged',         'color'=>'#3f51b5'],
        'hours_500'   => ['icon'=>'🧠','name'=>'Genius Mode',     'desc'=>'500 hours logged',         'color'=>'#9c27b0'],
        'perfect_day' => ['icon'=>'⭐','name'=>'Perfect Day',     'desc'=>'First 100% goal day',      'color'=>'#ffeb3b'],
        'perfect_week'=> ['icon'=>'🌟','name'=>'Perfect Week',    'desc'=>'7/7 goal days in a week',  'color'=>'#ff9800'],
        'no_excuse'   => ['icon'=>'🤐','name'=>'No Excuses',      'desc'=>'7 days without excuses',   'color'=>'#00c853'],
        'level_5'     => ['icon'=>'🥉','name'=>'Rising',          'desc'=>'Reached Level 5',          'color'=>'#795548'],
        'level_10'    => ['icon'=>'🥈','name'=>'Dedicated',       'desc'=>'Reached Level 10',         'color'=>'#9e9e9e'],
        'level_20'    => ['icon'=>'🥇','name'=>'Elite',           'desc'=>'Reached Level 20',         'color'=>'#ffc107'],
        'level_50'    => ['icon'=>'🏅','name'=>'Legendary',       'desc'=>'Reached Level 50',         'color'=>'#e94560'],
        'comeback'    => ['icon'=>'⚔️','name'=>'Comeback Kid',   'desc'=>'Rebuilt streak after break','color'=>'#ff5722'],
        'freeze_used' => ['icon'=>'🧊','name'=>'Ice Breaker',     'desc'=>'Used first streak freeze', 'color'=>'#00bcd4'],
        'xp_1000'     => ['icon'=>'💥','name'=>'XP Explosion',    'desc'=>'Earned 1,000 total XP',   'color'=>'#ff4081'],
        'xp_10000'    => ['icon'=>'🚀','name'=>'XP Rocket',       'desc'=>'Earned 10,000 total XP',  'color'=>'#7c4dff'],
    ];
}

// ─── Award XP ─────────────────────────────────────────────────────────────────

function awardXP(int $userId, int $xp, string $reason = ''): int {
    if ($xp <= 0) return 0;
    $db     = getDB();
    $streak = getStreakRow($userId);
    $tier   = $streak ? (int)floor($streak['current_streak'] / 7) : 0;
    $final  = (int)round($xp * (1 + $tier * XP_STREAK_MULTIPLIER));

    $newTotal = ($streak['total_xp'] ?? 0) + $final;
    $db->prepare("UPDATE streaks SET total_xp=total_xp+?, level=? WHERE user_id=?")
       ->execute([$final, levelFromXp($newTotal), $userId]);
    $db->prepare("INSERT INTO xp_log (user_id,xp_gained,reason) VALUES (?,?,?)")
       ->execute([$userId, $final, $reason]);
    return $final;
}

// ─── Check & Award Badges ─────────────────────────────────────────────────────

function checkAndAwardBadges(int $userId): array {
    $db        = getDB();
    $newBadges = [];
    $stmt      = $db->prepare("SELECT badge_key FROM badges WHERE user_id=?");
    $stmt->execute([$userId]);
    $earned = array_column($stmt->fetchAll(), 'badge_key');
    $defs   = allBadges();
    $streak = getStreakRow($userId);
    $cur    = $streak['current_streak'] ?? 0;
    $long   = $streak['longest_streak'] ?? 0;
    $xp     = $streak['total_xp'] ?? 0;
    $level  = $streak['level'] ?? 1;

    $u = $db->prepare("SELECT daily_goal_minutes FROM users WHERE id=?");
    $u->execute([$userId]);
    $goal = (int)$u->fetchColumn();

    $s = $db->prepare("SELECT COALESCE(SUM(minutes_studied),0) FROM study_logs WHERE user_id=?");
    $s->execute([$userId]);
    $totalH = (int)$s->fetchColumn() / 60;

    $award = function(string $key) use ($userId, $db, &$earned, &$newBadges, $defs) {
        if (in_array($key, $earned)) return;
        $db->prepare("INSERT IGNORE INTO badges (user_id,badge_key) VALUES (?,?)")->execute([$userId, $key]);
        $earned[]    = $key;
        $newBadges[] = array_merge(['key' => $key], $defs[$key] ?? []);
        awardXP($userId, 25, "Badge: " . ($defs[$key]['name'] ?? $key));
    };

    foreach ([3,7,14,30,60,100] as $n) if ($cur >= $n) $award("streak_{$n}");
    if ($totalH >= 10)  $award('hours_10');
    if ($totalH >= 50)  $award('hours_50');
    if ($totalH >= 100) $award('hours_100');
    if ($totalH >= 500) $award('hours_500');
    if ($xp >= 1000)    $award('xp_1000');
    if ($xp >= 10000)   $award('xp_10000');
    if ($level >= 5)    $award('level_5');
    if ($level >= 10)   $award('level_10');
    if ($level >= 20)   $award('level_20');
    if ($level >= 50)   $award('level_50');

    $s2 = $db->prepare("SELECT COALESCE(SUM(minutes_studied),0) FROM study_logs WHERE user_id=? AND log_date=?");
    $s2->execute([$userId, date('Y-m-d')]);
    if ((int)$s2->fetchColumn() >= $goal && $goal > 0) $award('perfect_day');

    $s3 = $db->prepare("
        SELECT COUNT(*) FROM (
            SELECT log_date FROM study_logs
            WHERE user_id=? AND log_date>=DATE_SUB(CURDATE(),INTERVAL 6 DAY)
            GROUP BY log_date HAVING SUM(minutes_studied)>=?
        ) t
    ");
    $s3->execute([$userId, $goal]);
    if ((int)$s3->fetchColumn() >= 7) $award('perfect_week');

    $s4 = $db->prepare("SELECT COUNT(*) FROM excuses WHERE user_id=? AND logged_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)");
    $s4->execute([$userId]);
    if ((int)$s4->fetchColumn() === 0 && $cur >= 7) $award('no_excuse');
    if ($cur >= 3 && $long > $cur) $award('comeback');

    return $newBadges;
}

// ─── Process Rewards After Study Log ─────────────────────────────────────────

function processRewards(int $userId, int $minutesJustLogged): array {
    $db = getDB();
    $u  = $db->prepare("SELECT daily_goal_minutes FROM users WHERE id=?");
    $u->execute([$userId]);
    $goal = (int)$u->fetchColumn();

    $xpEarned = awardXP($userId, $minutesJustLogged * XP_PER_MINUTE, 'Study session');

    $s = $db->prepare("SELECT COALESCE(SUM(minutes_studied),0) FROM study_logs WHERE user_id=? AND log_date=?");
    $s->execute([$userId, date('Y-m-d')]);
    $todayTotal = (int)$s->fetchColumn();

    $goalBonus = 0;
    if ($todayTotal >= $goal && ($todayTotal - $minutesJustLogged) < $goal) {
        $goalBonus  = awardXP($userId, XP_GOAL_BONUS, 'Daily goal completed!');
        $xpEarned  += $goalBonus;
    }

    $streak = getStreakRow($userId);
    if ($streak && $streak['current_streak'] > 0 && $streak['current_streak'] % XP_FREEZE_EARN_EVERY === 0) {
        $db->prepare("UPDATE streaks SET streak_freezes=streak_freezes+1 WHERE user_id=?")->execute([$userId]);
        $db->prepare("INSERT INTO freeze_log (user_id,action) VALUES (?,'earned')")->execute([$userId]);
    }

    $newBadges = checkAndAwardBadges($userId);
    $streak    = getStreakRow($userId); // refresh

    return [
        'xp_earned'  => $xpEarned,
        'goal_bonus' => $goalBonus,
        'new_badges' => $newBadges,
        'level_info' => xpProgressInLevel($streak['total_xp'] ?? 0),
    ];
}

// ─── Streak Freeze ────────────────────────────────────────────────────────────

function useStreakFreeze(int $userId): array {
    $db     = getDB();
    $streak = getStreakRow($userId);
    if (!$streak || $streak['streak_freezes'] < 1)
        return ['success' => false, 'message' => 'No streak freezes available.'];

    $yesterday = date('Y-m-d', strtotime('-1 day'));
    if ($streak['last_active_date'] !== $yesterday)
        return ['success' => false, 'message' => 'Freeze only works for a streak you missed yesterday.'];

    $db->prepare("UPDATE streaks SET streak_freezes=streak_freezes-1, last_active_date=CURDATE(), freeze_used_date=? WHERE user_id=?")
       ->execute([$yesterday, $userId]);
    $db->prepare("INSERT INTO freeze_log (user_id,action) VALUES (?,'used')")->execute([$userId]);
    checkAndAwardBadges($userId);
    return ['success' => true, 'message' => '🧊 Streak freeze applied! Your streak is safe.'];
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function getUserBadges(int $userId): array {
    $db   = getDB();
    $stmt = $db->prepare("SELECT badge_key, earned_at FROM badges WHERE user_id=? ORDER BY earned_at DESC");
    $stmt->execute([$userId]);
    $defs   = allBadges();
    $result = [];
    foreach ($stmt->fetchAll() as $r) {
        $k = $r['badge_key'];
        if (isset($defs[$k])) $result[] = array_merge($defs[$k], ['key' => $k, 'earned_at' => $r['earned_at']]);
    }
    return $result;
}

function getXpHistory(int $userId, int $days = 14): array {
    $db   = getDB();
    $stmt = $db->prepare("
        SELECT DATE(logged_at) AS day, SUM(xp_gained) AS xp
        FROM xp_log
        WHERE user_id=? AND logged_at>=DATE_SUB(CURDATE(),INTERVAL ? DAY)
        GROUP BY DATE(logged_at) ORDER BY day ASC
    ");
    $stmt->execute([$userId, $days]);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

function getLeagueTitle(int $streak): string {
    if ($streak >= 100) return '👑 Legendary';
    if ($streak >= 60)  return '💎 Diamond';
    if ($streak >= 30)  return '🥇 Gold';
    if ($streak >= 14)  return '🥈 Silver';
    if ($streak >= 7)   return '🥉 Bronze';
    return '🌱 Starter';
}

function getLeagueColor(int $streak): string {
    if ($streak >= 100) return '#9c27b0';
    if ($streak >= 60)  return '#00bcd4';
    if ($streak >= 30)  return '#ffc107';
    if ($streak >= 14)  return '#9e9e9e';
    if ($streak >= 7)   return '#795548';
    return '#4caf50';
}
