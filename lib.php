<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/mail.php';

function db(bool $selectDatabase = true): PDO {
    $c = require __DIR__ . '/config.php';
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $c['database'])) throw new RuntimeException('Invalid database name.');
    if (preg_match('/[;\s\x00-\x1F]/',$c['host']) || !ctype_digit((string)$c['port']) || (int)$c['port']<1 || (int)$c['port']>65535) throw new RuntimeException('Invalid database host or port.');
    $dsn = "mysql:host={$c['host']};port={$c['port']};charset=utf8mb4";
    if ($selectDatabase) $dsn .= ";dbname={$c['database']}";
    $options=[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    if (envValue('TF_DB_SSL_CA') !== '') { $options[PDO::MYSQL_ATTR_SSL_CA]=envValue('TF_DB_SSL_CA'); $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]=true; }
    $pdo = new PDO($dsn, $c['username'], $c['password'], $options);
    $pdo->exec("SET time_zone = '+08:00'");
    return $pdo;
}
function query(PDO $db, string $sql, array $params = []): PDOStatement {
    $stmt = $db->prepare($sql); $stmt->execute($params); return $stmt;
}
function rows(PDO $db, string $sql, array $params = []): array { return query($db, $sql, $params)->fetchAll(); }
function one(PDO $db, string $sql, array $params = []): ?array { return query($db, $sql, $params)->fetch() ?: null; }
function demand(bool $condition, string $message, int $status = 422): void {
    if (!$condition) { http_response_code($status); throw new DomainException($message); }
}
function field(array $data, string $key, int $max = 120): string {
    demand(isset($data[$key]) && is_string($data[$key]), "Please enter a valid $key.");
    $v = trim($data[$key]); demand($v !== '' && mb_check_encoding($v,'UTF-8') && !preg_match('/[\x00-\x1F\x7F]/u',$v) && mb_strlen($v) <= $max, "Please enter a valid $key (up to $max characters)."); return $v;
}
function number(array $data, string $key, int $min, int $max): int {
    $v = filter_var($data[$key] ?? null, FILTER_VALIDATE_INT); demand($v !== false && $v !== null && $v >= $min && $v <= $max, "$key must be between $min and $max."); return $v;
}
function audit(PDO $db, ?int $event, ?int $user, string $message): void { query($db, 'INSERT INTO audit_log(event_id,user_id,action) VALUES(?,?,?)', [$event, $user, $message]); }
function roundRobin(array $teams, bool $double): array {
    if (count($teams) % 2) $teams[] = null;
    $n = count($teams); $result = [];
    for ($r = 1; $r < $n; $r++) {
        for ($i = 0; $i < $n / 2; $i++) {
            $a = $teams[$i]; $b = $teams[$n - 1 - $i];
            if ($a !== null && $b !== null) $result[] = [$r, $r % 2 ? $a : $b, $r % 2 ? $b : $a];
        }
        $last = array_pop($teams); array_splice($teams, 1, 0, [$last]);
    }
    if ($double) { $first = $result; foreach ($first as [$r,$a,$b]) $result[] = [$r + $n - 1,$b,$a]; }
    return $result;
}
function standings(array $teams, array $matches): array {
    $table = [];
    foreach ($teams as $t) $table[$t['id']] = ['team_id'=>(int)$t['id'],'name'=>$t['name'],'color'=>$t['color'],'played'=>0,'wins'=>0,'losses'=>0,'gf'=>0,'ga'=>0,'diff'=>0,'points'=>0];
    foreach ($matches as $m) {
        if ($m['status'] !== 'confirmed') continue;
        $a = &$table[$m['team_a']]; $b = &$table[$m['team_b']];
        $a['played']++; $b['played']++; $a['gf'] += $m['score_a']; $a['ga'] += $m['score_b']; $b['gf'] += $m['score_b']; $b['ga'] += $m['score_a'];
        if ($m['score_a'] > $m['score_b']) { $a['wins']++; $a['points']++; $b['losses']++; } else { $b['wins']++; $b['points']++; $a['losses']++; }
        unset($a, $b);
    }
    $groups = []; foreach ($table as &$t) { $t['diff'] = $t['gf'] - $t['ga']; $groups[$t['points']][] = $t['team_id']; } unset($t);
    foreach ($table as &$t) {
        $t['h2h'] = 0;
        if (count($groups[$t['points']]) === 2) foreach ($matches as $m) {
            if ($m['status'] !== 'confirmed' || !in_array((int)$m['team_a'],$groups[$t['points']],true) || !in_array((int)$m['team_b'],$groups[$t['points']],true)) continue;
            $winner = $m['score_a'] > $m['score_b'] ? $m['team_a'] : $m['team_b']; if ((int)$winner === $t['team_id']) $t['h2h']++;
        }
    } unset($t);
    $result = array_values($table);
    usort($result, fn($a,$b) => ($b['points'] <=> $a['points']) ?: ($b['h2h'] <=> $a['h2h']) ?: ($b['diff'] <=> $a['diff']) ?: strcasecmp($a['name'],$b['name']));
    $rank = 0; $last = null;
    foreach ($result as $i => &$t) { $key = [$t['points'],$t['h2h'],$t['diff']]; if ($key !== $last) $rank = $i + 1; $t['rank'] = $rank; $last = $key; } unset($t);
    return $result;
}
function seedDemo(PDO $db): void {
    if ((int)query($db,'SELECT COUNT(*) FROM events')->fetchColumn()) return;
    query($db,'INSERT INTO events(name,game,team_size,max_teams,formation,format,race_to,deadline,roles,locked,demo) VALUES(?,?,?,?,?,?,?,?,?,1,1)', ['Friday Night Clash','Mobile Legends: Bang Bang',5,6,'random','single',2,date('Y-m-d H:i:s',strtotime('+7 days')),json_encode(['Tank / Roam','Jungle','Mid','Gold','EXP'])]);
    $eid = (int)$db->lastInsertId(); $ids=[];
    $names = ['Neon Wolves','Crimson Reign','Void Walkers','Solar Sentinels','Frost Legion','Phantom Five'];
    $colors = ['#a78bfa','#fb7185','#38bdf8','#fbbf24','#5eead4','#e879f9'];
    $handles = ['Astra','Vex','Kairo','Luna','Onyx','Blaze','Echo','Riven','Nova','Raze','Ghost','Flux','Nyx','Shade','Zero','Sol','Atlas','Zen','Aero','Orion','Frost','Haze','Yuki','Storm','Glacier','Phantom','Rogue','Kira','Ace','Cipher'];
    $roles = ['Tank / Roam','Jungle','Mid','Gold','EXP'];
    foreach ($names as $i=>$name) {
        query($db,'INSERT INTO teams(event_id,name,color) VALUES(?,?,?)',[$eid,$name,$colors[$i]]); $tid=(int)$db->lastInsertId(); $ids[]=$tid;
        for ($j=0;$j<5;$j++) {
            query($db,'INSERT INTO players(event_id,display_name,game_id,primary_role,checked_in,fair_play_at) VALUES(?,?,?,?,1,NOW())',[$eid,$handles[$i*5+$j],'DEMO-'.($i*5+$j+1),$roles[$j]]); $pid=(int)$db->lastInsertId();
            query($db,'INSERT INTO team_members VALUES(?,?)',[$tid,$pid]); if ($j===0) query($db,'UPDATE teams SET captain_id=? WHERE id=?',[$pid,$tid]);
        }
    }
    foreach (roundRobin($ids,false) as $idx=>[$r,$a,$b]) query($db,'INSERT INTO matches(event_id,round_no,team_a,team_b,score_a,score_b,status,confirmed_at) VALUES(?,?,?,?,?,?,?,?)',[$eid,$r,$a,$b,$idx<3?2:null,$idx<3?($idx%2):null,$idx<3?'confirmed':'scheduled',$idx<3?date('Y-m-d H:i:s'):null]);
    audit($db,$eid,null,'Sample tournament created. Demo rosters and results are fictional. Create a new event for real play.');
}
