<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(24));

function currentUser(PDO $db): ?array { return isset($_SESSION['uid']) ? one($db,'SELECT id,name,email,role FROM users WHERE id=?',[$_SESSION['uid']]) : null; }
function admin(?array $u): void { demand($u && $u['role'] === 'organizer','Organizer access required.',403); }
function official(?array $u): void { demand($u && in_array($u['role'],['organizer','referee'],true),'Referee or organizer access required.',403); }
function editable(array $e): void { demand(!(bool)$e['locked'],'Rosters are locked. Reset the tournament before changing teams.'); }
function captain(PDO $db, ?array $u, int $tid): bool {
    if (!$u) return false; if ($u['role'] === 'organizer') return true;
    return (bool)one($db,'SELECT t.id FROM teams t JOIN players p ON p.id=t.captain_id WHERE t.id=? AND p.user_id=?',[$tid,$u['id']]);
}
function team(PDO $db, int $tid, int $eid): array {
    $t=one($db,'SELECT * FROM teams WHERE id=? AND event_id=?',[$tid,$eid]); demand((bool)$t,'Team not found.'); return $t;
}
function putMember(PDO $db, array $e, int $tid, int $pid): void {
    team($db,$tid,(int)$e['id']);
    demand((bool)one($db,'SELECT id FROM players WHERE id=? AND event_id=? AND checked_in=1',[$pid,$e['id']]),'Only checked-in players from this event can join a team.');
    demand(!one($db,'SELECT player_id FROM team_members WHERE player_id=?',[$pid]),'This player already belongs to a team.');
    demand((int)query($db,'SELECT COUNT(*) FROM team_members WHERE team_id=?',[$tid])->fetchColumn() < (int)$e['team_size'],'This team is full.');
    query($db,'INSERT INTO team_members VALUES(?,?)',[$tid,$pid]); query($db,'DELETE FROM join_requests WHERE player_id=?',[$pid]);
}
function state(PDO $db, ?array $u): array {
    $events=rows($db,'SELECT * FROM events ORDER BY demo ASC,id DESC');
    foreach($events as &$e) {
        $e['roles']=json_decode($e['roles'],true);
        $e['players']=rows($db,'SELECT p.*, tm.team_id FROM players p LEFT JOIN team_members tm ON tm.player_id=p.id WHERE p.event_id=? ORDER BY p.id',[$e['id']]);
        $e['teams']=rows($db,'SELECT * FROM teams WHERE event_id=? ORDER BY id',[$e['id']]);
        $e['matches']=rows($db,'SELECT * FROM matches WHERE event_id=? ORDER BY round_no,id',[$e['id']]);
        $e['standings']=standings($e['teams'],$e['matches']);
        $e['requests']=$u ? rows($db,'SELECT r.*,p.display_name,t.name AS team_name FROM join_requests r JOIN players p ON p.id=r.player_id JOIN teams t ON t.id=r.team_id WHERE t.event_id=?',[$e['id']]):[];
        $e['audit']=rows($db,'SELECT a.id,a.action,a.created_at,COALESCE(u.name,\'System\') AS actor FROM audit_log a LEFT JOIN users u ON u.id=a.user_id WHERE a.event_id=? ORDER BY a.id DESC LIMIT 50',[$e['id']]);
    } unset($e);
    return ['user'=>$u,'csrf'=>$_SESSION['csrf'],'needsSetup'=>(int)query($db,'SELECT COUNT(*) FROM users')->fetchColumn()===0,'events'=>$events,'users'=>$u && $u['role']==='organizer'?rows($db,'SELECT id,name,email,role FROM users ORDER BY id'):[]];
}
try {
    $db=db();
    if ($_SERVER['REQUEST_METHOD']==='GET') { echo json_encode(state($db,currentUser($db))); exit; }
    demand($_SERVER['REQUEST_METHOD']==='POST','Method not allowed.',405);
    demand(hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''),'Session expired. Refresh the page and try again.',403);
    $d=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
    $action=$d['action']??''; $u=currentUser($db);
    // Serialize mutations, including first-organizer creation and roster membership checks.
    demand((int)query($db,"SELECT GET_LOCK('tfv1_mutation',10)")->fetchColumn()===1,'The system is busy. Please try again.',503);
    $db->beginTransaction();
    if (in_array($action,['setup','signup','login','logout'],true)) {
        if ($action==='logout') { unset($_SESSION['uid']); session_regenerate_id(true); }
        elseif ($action==='login') {
            $account=one($db,'SELECT * FROM users WHERE email=?',[strtolower(field($d,'email',190))]);
            demand($account && password_verify((string)($d['password']??''),$account['password_hash']),'Email or password is incorrect.',401);
            session_regenerate_id(true); $_SESSION['uid']=(int)$account['id'];
        } else {
            $empty=(int)query($db,'SELECT COUNT(*) FROM users')->fetchColumn()===0;
            demand($action!=='setup'||$empty,'Organizer setup is already complete.'); demand($action!=='signup'||!$empty,'Set up the first organizer first.');
            $email=strtolower(field($d,'email',190)); demand((bool)filter_var($email,FILTER_VALIDATE_EMAIL),'Enter a valid email address.');
            $password=(string)($d['password']??''); demand(strlen($password)>=10 && strlen($password)<=128,'Use a password of 10–128 characters.');
            demand(($d['fair_play']??false)===true,'Accept the fair-play agreement to continue.');
            query($db,'INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,?)',[field($d,'name',80),$email,password_hash($password,PASSWORD_DEFAULT),$action==='setup'?'organizer':'player']);
            $_SESSION['uid']=(int)$db->lastInsertId(); session_regenerate_id(true);
        }
    } else {
        demand((bool)$u,'Sign in to continue.',401);
        if ($action==='create_event') {
            admin($u); $roles=array_values(array_unique(array_filter(array_map('trim',explode(',',field($d,'roles',500))))));
            demand(count($roles)>0 && count($roles)<=20 && max(array_map('mb_strlen',$roles))<=60,'Enter 1–20 roles, up to 60 characters each.');
            demand(in_array($d['formation']??'', ['captain','free','random'],true),'Choose a team formation mode.'); demand(in_array($d['format']??'', ['single','double'],true),'Choose a tournament format.');
            $deadline=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',(string)($d['deadline']??'')); demand($deadline && $deadline->format('Y-m-d\TH:i')===$d['deadline'] && $deadline->getTimestamp()>time(),'Registration deadline must be a valid future date (Manila time).');
            query($db,'INSERT INTO events(name,game,team_size,max_teams,formation,format,race_to,deadline,roles,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)',[field($d,'name'),field($d,'game',100),number($d,'team_size',1,10),number($d,'max_teams',2,32),$d['formation'],$d['format'],number($d,'race_to',1,5),$deadline->format('Y-m-d H:i:s'),json_encode($roles),$u['id']]);
            $eid=(int)$db->lastInsertId(); audit($db,$eid,(int)$u['id'],'Created event and published competition rules.');
        } elseif ($action==='set_role') {
            admin($u); $uid=number($d,'user_id',1,PHP_INT_MAX); demand($uid!==(int)$u['id'],'You cannot change your own organizer role.'); demand(in_array($d['role']??'', ['player','referee','organizer'],true),'Invalid permission.');
            demand((bool)one($db,'SELECT id FROM users WHERE id=?',[$uid]),'Account not found.'); query($db,'UPDATE users SET role=? WHERE id=?',[$d['role'],$uid]); audit($db,null,(int)$u['id'],"Changed account #$uid permission to {$d['role']}.");
        } else {
            $eid=number($d,'event_id',1,PHP_INT_MAX); $e=one($db,'SELECT * FROM events WHERE id=? FOR UPDATE',[$eid]); demand((bool)$e,'Event not found.'); $e['roles']=json_decode($e['roles'],true);
            $uid=(int)$u['id'];
            switch($action) {
                case 'register_player':
                    editable($e); demand(strtotime($e['deadline'])>=time(),'Registration deadline has passed.');
                    demand(($d['fair_play']??false)===true,'You must accept the fair-play agreement.');
                    demand((int)query($db,'SELECT COUNT(*) FROM players WHERE event_id=?',[$eid])->fetchColumn() < $e['team_size']*$e['max_teams'],'Registration is full.');
                    $role=field($d,'primary_role',60); $second=trim((string)($d['secondary_role']??'')); demand(in_array($role,$e['roles'],true) && ($second===''||in_array($second,$e['roles'],true)),'Choose a configured game position.');
                    demand($second===''||$second!==$role,'Choose a different secondary position.');
                    query($db,'INSERT INTO players(event_id,user_id,display_name,game_id,primary_role,secondary_role,fair_play_at) VALUES(?,?,?,?,?,?,NOW())',[$eid,$uid,field($d,'display_name',80),field($d,'game_id',100),$role,$second]);
                    audit($db,$eid,$uid,'Registered for the event and accepted the fair-play agreement.'); break;
                case 'checkin':
                    editable($e); $pid=number($d,'player_id',1,PHP_INT_MAX); $p=one($db,'SELECT * FROM players WHERE id=? AND event_id=?',[$pid,$eid]); demand((bool)$p,'Player not found.');
                    $member=one($db,'SELECT team_id FROM team_members WHERE player_id=?',[$pid]);
                    demand($u['role']==='organizer'||(int)$p['user_id']===$uid||($member&&captain($db,$u,(int)$member['team_id'])),'You can only check in yourself or your team.',403);
                    query($db,'UPDATE players SET checked_in=1 WHERE id=?',[$pid]); audit($db,$eid,$uid,'Checked in '.$p['display_name'].'.'); break;
                case 'create_team':
                    editable($e); demand($u['role']==='organizer'||$e['formation']==='free','Only the organizer can create draft teams.',403);
                    demand($e['formation']!=='captain'||(int)$e['draft_pick']===0,'Create all captain teams before the first draft pick. Reset the tournament to change the draft order.');
                    demand((int)query($db,'SELECT COUNT(*) FROM teams WHERE event_id=?',[$eid])->fetchColumn()<(int)$e['max_teams'],'Maximum team count reached.');
                    $pid=number($d,'captain_id',1,PHP_INT_MAX); $p=one($db,'SELECT * FROM players WHERE id=? AND event_id=? AND checked_in=1',[$pid,$eid]); demand((bool)$p,'Choose a checked-in captain.');
                    demand($u['role']==='organizer'||(int)$p['user_id']===$uid,'You must captain your own team.',403);
                    query($db,'INSERT INTO teams(event_id,name,captain_id,color) VALUES(?,?,?,?)',[$eid,field($d,'name',80),$pid,['#a78bfa','#fb7185','#38bdf8','#fbbf24','#5eead4','#e879f9'][random_int(0,5)]]);
                    $tid=(int)$db->lastInsertId(); putMember($db,$e,$tid,$pid); audit($db,$eid,$uid,'Created team '.$d['name'].'.'); break;
                case 'join_team':
                    editable($e); demand($e['formation']==='free','Join requests are available in Free Mode.'); $tid=number($d,'team_id',1,PHP_INT_MAX); team($db,$tid,$eid);
                    $p=one($db,'SELECT * FROM players WHERE event_id=? AND user_id=? AND checked_in=1',[$eid,$uid]); demand((bool)$p,'Register and check in first.');
                    demand(!one($db,'SELECT player_id FROM team_members WHERE player_id=?',[$p['id']]),'You already belong to a team.');
                    demand((int)query($db,'SELECT COUNT(*) FROM team_members WHERE team_id=?',[$tid])->fetchColumn()<(int)$e['team_size'],'This team is full.');
                    query($db,'INSERT INTO join_requests(team_id,player_id) VALUES(?,?)',[$tid,$p['id']]); audit($db,$eid,$uid,'Requested to join a team.'); break;
                case 'review_request':
                    editable($e); $req=one($db,'SELECT r.* FROM join_requests r JOIN teams t ON t.id=r.team_id WHERE r.id=? AND t.event_id=?',[number($d,'request_id',1,PHP_INT_MAX),$eid]); demand((bool)$req,'Request not found.');
                    demand(captain($db,$u,(int)$req['team_id']),'Captain approval required.',403);
                    if (($d['approve']??false)===true) putMember($db,$e,(int)$req['team_id'],(int)$req['player_id']);
                    query($db,'DELETE FROM join_requests WHERE id=?',[$req['id']]); audit($db,$eid,$uid,($d['approve']?'Approved':'Declined').' a team join request.'); break;
                case 'draft_pick':
                    editable($e); demand($e['formation']==='captain','This event is not in Captain Mode.');
                    $teams=rows($db,'SELECT * FROM teams WHERE event_id=? ORDER BY id',[$eid]); $n=count($teams); demand($n>=2,'Create at least two teams with captains first.');
                    $pick=(int)$e['draft_pick']; $cycle=intdiv($pick,$n); $index=$cycle%2===0?$pick%$n:$n-1-$pick%$n; $tid=(int)$teams[$index]['id'];
                    demand(captain($db,$u,$tid),'It is '.$teams[$index]['name'].'’s turn.',403);
                    putMember($db,$e,$tid,number($d,'player_id',1,PHP_INT_MAX)); query($db,'UPDATE events SET draft_pick=draft_pick+1 WHERE id=?',[$eid]); audit($db,$eid,$uid,'Draft pick for '.$teams[$index]['name'].'.'); break;
                case 'shuffle':
                    admin($u); editable($e); demand($e['formation']==='random','Random shuffle must be selected when creating the event.');
                    demand(!one($db,'SELECT id FROM matches WHERE event_id=?',[$eid]),'Reset the existing schedule before reshuffling.');
                    $players=rows($db,'SELECT id FROM players WHERE event_id=? AND checked_in=1',[$eid]); $count=count($players);
                    demand($count >= 2*$e['team_size'],'At least two full teams of checked-in players are required.'); demand($count % $e['team_size']===0,'Checked-in players must divide evenly into full teams. Ask remaining players to check in.');
                    demand($count/$e['team_size']<=$e['max_teams'],'Too many checked-in players.');
                    query($db,'DELETE FROM teams WHERE event_id=?',[$eid]); shuffle($players);
                    $colors=['#a78bfa','#fb7185','#38bdf8','#fbbf24','#5eead4','#e879f9'];
                    foreach(array_chunk($players,(int)$e['team_size']) as $i=>$members) {
                        query($db,'INSERT INTO teams(event_id,name,captain_id,color) VALUES(?,?,?,?)',[$eid,'Squad '.str_pad((string)($i+1),2,'0',STR_PAD_LEFT),$members[0]['id'],$colors[$i%6]]); $tid=(int)$db->lastInsertId();
                        foreach($members as $p) query($db,'INSERT INTO team_members VALUES(?,?)',[$tid,$p['id']]);
                    } audit($db,$eid,$uid,'Randomly shuffled checked-in players into fixed teams.'); break;
                case 'lock':
                    admin($u); editable($e); $teams=rows($db,'SELECT t.id,COUNT(tm.player_id) AS members FROM teams t LEFT JOIN team_members tm ON tm.team_id=t.id WHERE t.event_id=? GROUP BY t.id',[$eid]); demand(count($teams)>=2,'At least two teams are required.');
                    foreach($teams as $t) demand((int)$t['members']===(int)$e['team_size'],'Every team must have exactly '.$e['team_size'].' checked-in players.');
                    query($db,'UPDATE events SET locked=1 WHERE id=?',[$eid]); query($db,'DELETE r FROM join_requests r JOIN teams t ON t.id=r.team_id WHERE t.event_id=?',[$eid]); audit($db,$eid,$uid,'Locked all team rosters.'); break;
                case 'schedule':
                    admin($u); demand((bool)$e['locked'],'Lock rosters before generating the schedule.'); demand(!one($db,'SELECT id FROM matches WHERE event_id=?',[$eid]),'Schedule already exists. Use Reset tournament to regenerate it.');
                    $ids=array_map('intval',array_column(rows($db,'SELECT id FROM teams WHERE event_id=? ORDER BY id',[$eid]),'id'));
                    foreach(roundRobin($ids,$e['format']==='double') as [$r,$a,$b]) query($db,'INSERT INTO matches(event_id,round_no,team_a,team_b) VALUES(?,?,?,?)',[$eid,$r,$a,$b]); audit($db,$eid,$uid,'Generated '.$e['format'].' round-robin schedule.'); break;
                case 'submit_score':
                    $m=one($db,'SELECT * FROM matches WHERE id=? AND event_id=?',[number($d,'match_id',1,PHP_INT_MAX),$eid]); demand((bool)$m,'Match not found.');
                    demand(in_array($u['role'],['organizer','referee'],true)||captain($db,$u,(int)$m['team_a'])||captain($db,$u,(int)$m['team_b']),'A participating captain or referee must submit the score.',403);
                    demand($m['status']==='scheduled','This match already has a submitted result. A referee must return it for correction.');
                    $a=number($d,'score_a',0,(int)$e['race_to']); $b=number($d,'score_b',0,(int)$e['race_to']); demand(max($a,$b)===(int)$e['race_to'] && min($a,$b)<(int)$e['race_to'],'Exactly one team must reach the race-to target.');
                    query($db,'UPDATE matches SET score_a=?,score_b=?,status=\'pending\',submitted_by=?,submitted_at=NOW() WHERE id=?',[$a,$b,$uid,$m['id']]); audit($db,$eid,$uid,"Submitted match #{$m['id']}: {$a}–{$b} for referee review."); break;
                case 'confirm_score':
                case 'return_score':
                    official($u); $m=one($db,'SELECT * FROM matches WHERE id=? AND event_id=?',[number($d,'match_id',1,PHP_INT_MAX),$eid]); demand((bool)$m,'Match not found.');
                    if ($action==='confirm_score') {
                        demand($m['status']==='pending','Only pending results can be confirmed.'); demand((int)$m['submitted_by']!==$uid,'Another referee or organizer must confirm your submission.');
                        demand(!one($db,'SELECT t.id FROM teams t JOIN team_members tm ON tm.team_id=t.id JOIN players p ON p.id=tm.player_id WHERE t.id IN (?,?) AND p.user_id=?',[$m['team_a'],$m['team_b'],$uid]),'A participating player cannot confirm this match.');
                        query($db,'UPDATE matches SET status=\'confirmed\',confirmed_by=?,confirmed_at=NOW() WHERE id=?',[$uid,$m['id']]); audit($db,$eid,$uid,"Confirmed match #{$m['id']}; standings updated.");
                    } else {
                        demand($m['status']!=='scheduled','No result has been submitted.'); $reason=field($d,'reason',300);
                        query($db,'UPDATE matches SET status=\'scheduled\',score_a=NULL,score_b=NULL,submitted_by=NULL,confirmed_by=NULL,submitted_at=NULL,confirmed_at=NULL WHERE id=?',[$m['id']]); audit($db,$eid,$uid,"Returned match #{$m['id']} for correction ($m[score_a]–$m[score_b]): $reason");
                    } break;
                case 'reset':
                    admin($u); demand(($d['confirmation']??'')===$e['name'],'Type the exact event name to confirm this destructive reset.'); $reason=field($d,'reason',300);
                    query($db,'DELETE FROM matches WHERE event_id=?',[$eid]); query($db,'DELETE FROM teams WHERE event_id=?',[$eid]); query($db,'UPDATE events SET locked=0,draft_pick=0 WHERE id=?',[$eid]); audit($db,$eid,$uid,'RESET: removed all teams, schedules and results; preserved registrations. Reason: '.$reason); break;
                default: demand(false,'Unknown action.',400);
            }
        }
    }
    $db->commit(); query($db,"SELECT RELEASE_LOCK('tfv1_mutation')");
    echo json_encode(['ok'=>true,'created_event_id'=>$eid??null,'state'=>state($db,currentUser($db))]);
} catch (DomainException $e) {
    if (isset($db)&&$db->inTransaction()) $db->rollBack(); if (http_response_code()<400) http_response_code(422); echo json_encode(['error'=>$e->getMessage()]);
} catch (PDOException $e) {
    if (isset($db)&&$db->inTransaction()) $db->rollBack();
    $duplicate=$e->getCode()==='23000'; http_response_code($duplicate?409:503);
    error_log('TFv1 database: '.$e->getMessage());
    echo json_encode(['error'=>$duplicate?'That email, game ID, team name, registration or request already exists.':'MySQL is unavailable or TFv1 has not been installed. Start MySQL in XAMPP, then run php install.php.','database_unavailable'=>!$duplicate]);
} catch (Throwable $e) {
    if (isset($db)&&$db->inTransaction()) $db->rollBack(); http_response_code(500); error_log('TFv1: '.$e->getMessage()); echo json_encode(['error'=>'Unable to complete the request. Check your input and try again.']);
} finally {
    if (isset($db)) { try { query($db,"SELECT RELEASE_LOCK('tfv1_mutation')"); } catch (Throwable $ignored) {} }
}
