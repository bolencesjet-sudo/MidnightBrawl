<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
securityHeaders();

function currentUser(PDO $db): ?array {
    $u=isset($_SESSION['uid']) ? one($db,'SELECT id,name,email,role,username,auth_version FROM users WHERE id=? AND disabled_at IS NULL AND email_verified_at IS NOT NULL',[$_SESSION['uid']]) : null;
    if (!$u || (int)$u['auth_version']!==(int)($_SESSION['auth_version']??0) || ($u['role']==='organizer' && empty($_SESSION['admin_login']))) return null;
    unset($u['auth_version']); return $u;
}
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
        $e['requests']=$u ? rows($db,'SELECT r.*,p.display_name,t.name AS team_name FROM join_requests r JOIN players p ON p.id=r.player_id JOIN teams t ON t.id=r.team_id LEFT JOIN players c ON c.id=t.captain_id WHERE t.event_id=? AND (?=\'organizer\' OR p.user_id=? OR c.user_id=?)',[$e['id'],$u['role'],$u['id'],$u['id']]):[];
        $e['audit']=rows($db,'SELECT a.id,a.action,a.created_at,COALESCE(u.name,\'System\') AS actor FROM audit_log a LEFT JOIN users u ON u.id=a.user_id WHERE a.event_id=? ORDER BY a.id DESC LIMIT 50',[$e['id']]);
    } unset($e);
    return ['user'=>$u,'csrf'=>$_SESSION['csrf'],'verificationRequired'=>isset($_SESSION['pending_uid']),'loginVerificationRequired'=>isset($_SESSION['pending_login']),'emailChangePending'=>(bool)($u && isset($_SESSION['pending_email'])),'passwordChangeRequired'=>(bool)($u && isset($_SESSION['recovery_password_hash'])),'needsSetup'=>setupAllowed() && (int)query($db,'SELECT COUNT(*) FROM users')->fetchColumn()===0,'events'=>$events,'users'=>$u && $u['role']==='organizer'?rows($db,'SELECT id,name,email,username,role,disabled_at,email_verified_at FROM users ORDER BY id'):[]];
}
try {
    $databaseStage='configuration';
    validateEnvironment();
    demand(!production() || secureRequest(),'HTTPS is required.',403);
    $databaseStage='connection'; $db=db();
    $databaseStage='session';
    startSecureSession();
    $databaseStage='request';
    $ip=$_SERVER['REMOTE_ADDR']??'unknown';
    if ($_SERVER['REQUEST_METHOD']==='GET') { rateLimit($db,'read',$ip,240,60); $response=state($db,currentUser($db)); session_write_close(); echo json_encode($response); exit; }
    demand($_SERVER['REQUEST_METHOD']==='POST','Method not allowed.',405);
    demand(strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))==='application/json','Use application/json.',415);
    $origin=$_SERVER['HTTP_ORIGIN']??'';
    demand(requestOriginAllowed($origin),'Origin is not allowed. Open the official tournament website and try again. If this continues, ask the organizer to check the website URL configuration.',403);
    demand(hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''),'Session expired. Refresh the page and try again.',403);
    $raw=file_get_contents('php://input',false,null,0,16385); demand(strlen($raw)<=16384,'Request is too large.',413);
    try { $d=json_decode($raw,true,16,JSON_THROW_ON_ERROR); } catch (JsonException $e) { demand(false,'Invalid JSON.',400); }
    demand(is_array($d) && str_starts_with(ltrim($raw),'{'),'Expected a JSON object.',400);
    $action=field($d,'action',40); $databaseStage='action:'.$action; $u=currentUser($db);
    rateLimit($db,'write',$ip,1800,600);
    if (in_array($action,['setup','signup','login','admin_login'],true)) {
        rateLimit($db,'auth-ip',$ip,60,900);
        $identity=strtolower(is_string($d['email']??null)?$d['email']:(is_string($d['username']??null)?$d['username']:''));
        rateLimit($db,'auth-account',$identity,10,900);
        if ($action==='signup') rateLimit($db,'signup',$ip,20,3600);
    }
    if (in_array($action,['verify_email','resend_verification'],true)) {
        rateLimit($db,'verification-ip',$ip,30,600);
        rateLimit($db,$action,(string)($_SESSION['pending_uid']??$ip),$action==='verify_email'?5:1,$action==='verify_email'?600:60,$action==='resend_verification');
    }
    if ($u && in_array($action,['set_role','set_disabled','delete_event'],true)) rateLimit($db,'admin-confirm',(string)$u['id'],10,900);
    if ($action==='forgot_password') {
        $email=strtolower(field($d,'email',190));
        demand((bool)filter_var($email,FILTER_VALIDATE_EMAIL),'Enter a valid email address.');
        rateLimit($db,'recovery-ip',$ip,10,900);
        rateLimit($db,'recovery-email',$email,1,60,true);
    }
    if ($u && $action==='change_password') rateLimit($db,'password-change',(string)$u['id'],10,900);
    if (in_array($action,['verify_login','resend_login','request_email_change','confirm_email_change'],true)) {
        rateLimit($db,'account-code-ip',$ip,60,600);
        rateLimit($db,$action,(string)($u['id']??$_SESSION['pending_login']['uid']??$ip),$action==='resend_login'?1:10,$action==='resend_login'?60:600,$action==='resend_login');
    }
    // Serialize mutations, including first-organizer creation and roster membership checks.
    demand((int)query($db,"SELECT GET_LOCK('tfv1_mutation',10)")->fetchColumn()===1,'The system is busy. Please try again.',503);
    $db->beginTransaction();
    if ($action==='verify_login' || $action==='resend_login') {
        $pending=$_SESSION['pending_login']??null;
        demand((bool)$pending,'Enter your email and password before requesting a sign-in code.',401);
        $account=one($db,'SELECT * FROM users WHERE id=? AND disabled_at IS NULL AND email_verified_at IS NOT NULL',[$pending['uid']]);
        demand($account && (int)$account['auth_version']===$pending['version'] && $account['email']===$pending['email'] && $account['role']===$pending['role'],'Account changed. Sign in again.',401);
        if ($action==='resend_login') {
            $newCode=sendAccountCode($account['email'],'sign-in');
            $_SESSION['pending_login']=array_merge($pending,$newCode,['attempts'=>$pending['attempts']]);
        } else {
            verifyAccountCode($_SESSION['pending_login'],$d);
            $recovery=$pending['recovery_hash']??null;
            if ($recovery) {
                $reset=one($db,'SELECT password_hash FROM password_resets WHERE user_id=? AND expires_at>NOW()',[$account['id']]);
                demand($reset && hash_equals($reset['password_hash'],$recovery),'Temporary password expired or already used. Sign in again.',401);
                query($db,'DELETE FROM password_resets WHERE user_id=?',[$account['id']]);
                query($db,'UPDATE users SET auth_version=auth_version+1 WHERE id=?',[$account['id']]);
                $account['auth_version']++;
            }
            signIn((int)$account['id'],$account['role']==='organizer',(int)$account['auth_version']);
            if ($recovery) $_SESSION['recovery_password_hash']=$recovery;
        }
    } elseif ($action==='forgot_password') {
        $account=one($db,"SELECT id,email FROM users WHERE email=? AND role<>'organizer' AND disabled_at IS NULL AND email_verified_at IS NOT NULL",[$email]);
        if ($account) sendTemporaryPassword($db,(int)$account['id'],$account['email']);
    } elseif (in_array($action,['setup','signup','login','admin_login','logout','verify_email','resend_verification'],true)) {
        if ($action==='logout') { $_SESSION=[]; session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32)); }
        elseif ($action==='verify_email' || $action==='resend_verification') {
            $account=one($db,'SELECT * FROM users WHERE id=? AND disabled_at IS NULL AND email_verified_at IS NULL',[$_SESSION['pending_uid']??0]);
            demand((bool)$account,'Sign in or register before verifying your email.',401);
            if ($action==='resend_verification') sendVerification($db,(int)$account['id'],$account['email']);
            else {
                $v=one($db,'SELECT * FROM email_verifications WHERE user_id=? AND expires_at>NOW()',[$account['id']]);
                $code=field($d,'code',6);
                demand((bool)preg_match('/^[0-9]{6}$/',$code) && $v && hash_equals($v['code_hash'],hash_hmac('sha256',$code,envValue('TF_APP_KEY'))),'Invalid or expired verification code.');
                query($db,'UPDATE users SET email_verified_at=NOW() WHERE id=?',[$account['id']]);
                query($db,'DELETE FROM email_verifications WHERE user_id=?',[$account['id']]); signIn((int)$account['id'],!empty($_SESSION['pending_admin']),(int)$account['auth_version']);
            }
        } elseif ($action==='login' || $action==='admin_login') {
            $identifier=strtolower(field($d,$action==='admin_login'?'username':'email',190));
            $account=one($db,$action==='admin_login'?'SELECT * FROM users WHERE username=? OR email=?':'SELECT * FROM users WHERE email=?',$action==='admin_login'?[$identifier,$identifier]:[$identifier]);
            $valid=password_verify(passwordValue($d),$account['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
            $recovery=null;
            if (!$valid && $account && $action==='login' && $account['role']!=='organizer' && !$account['disabled_at'] && $account['email_verified_at']) {
                $recovery=one($db,'SELECT password_hash FROM password_resets WHERE user_id=? AND expires_at>NOW()',[$account['id']]);
                $valid=$recovery && password_verify(passwordValue($d),$recovery['password_hash']);
            }
            demand($account && $valid && !$account['disabled_at'] && (($action==='admin_login')===($account['role']==='organizer')),'Account or password is incorrect. Use the appropriate sign-in form.',401);
            if (!$account['email_verified_at']) { sendVerification($db,(int)$account['id'],$account['email']); $_SESSION=[]; session_regenerate_id(true); $_SESSION['pending_uid']=(int)$account['id']; $_SESSION['pending_admin']=$action==='admin_login'; $_SESSION['csrf']=bin2hex(random_bytes(32)); }
            else {
                $challenge=sendAccountCode($account['email'],'sign-in');
                if (!$recovery && password_needs_rehash($account['password_hash'],PASSWORD_DEFAULT)) query($db,'UPDATE users SET password_hash=? WHERE id=?',[password_hash((string)$d['password'],PASSWORD_DEFAULT),$account['id']]);
                $_SESSION=[]; session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32));
                $_SESSION['pending_login']=array_merge($challenge,['uid'=>(int)$account['id'],'version'=>(int)$account['auth_version'],'email'=>$account['email'],'role'=>$account['role'],'recovery_hash'=>$recovery['password_hash']??null]);
            }
        } else {
            $empty=(int)query($db,'SELECT COUNT(*) FROM users')->fetchColumn()===0;
            demand($action!=='setup'||($empty && setupAllowed()),'Public organizer setup is disabled. Create an admin with php admin.php.',403); demand($action!=='signup'||!$empty,'The administrator must finish setup first.');
            $email=strtolower(field($d,'email',190)); demand((bool)filter_var($email,FILTER_VALIDATE_EMAIL),'Enter a valid email address.');
            $password=passwordValue($d); strongPassword($password);
            demand(($d['fair_play']??false)===true,'Accept the fair-play agreement to continue.');
            query($db,'INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,?)',[field($d,'name',80),$email,password_hash($password,PASSWORD_DEFAULT),$action==='setup'?'organizer':'player']);
            $newUid=(int)$db->lastInsertId();
            if ($action==='setup') { query($db,'UPDATE users SET email_verified_at=NOW() WHERE id=?',[$newUid]); signIn($newUid,true); }
            else { sendVerification($db,$newUid,$email); $_SESSION=[]; session_regenerate_id(true); $_SESSION['pending_uid']=$newUid; $_SESSION['csrf']=bin2hex(random_bytes(32)); }
        }
    } else {
        demand((bool)$u,'Sign in to continue.',401);
        if ($action==='request_email_change') {
            admin($u); reauthenticate($db,$u,$d);
            $email=strtolower(field($d,'email',190));
            demand((bool)filter_var($email,FILTER_VALIDATE_EMAIL),'Enter a valid email address.');
            demand($email!==$u['email'],'Enter a different email address.');
            demand(!one($db,'SELECT id FROM users WHERE email=?',[$email]),'That email address is already in use.');
            rateLimit($db,'email-change-send',(string)$u['id'],1,60,true);
            $version=(int)query($db,'SELECT auth_version FROM users WHERE id=?',[$u['id']])->fetchColumn();
            $_SESSION['pending_email']=array_merge(sendAccountCode($email,'email change'),['uid'=>(int)$u['id'],'email'=>$email,'version'=>$version]);
        } elseif ($action==='confirm_email_change') {
            admin($u); demand(isset($_SESSION['pending_email']),'Request an email change first.',401);
            $pending=$_SESSION['pending_email'];
            demand($pending['uid']===(int)$u['id'] && $pending['version']===(int)$_SESSION['auth_version'],'Account changed. Request an email change again.',401);
            verifyAccountCode($_SESSION['pending_email'],$d);
            demand(!one($db,'SELECT id FROM users WHERE email=?',[$pending['email']]),'That email address is already in use.');
            query($db,'UPDATE users SET email=?,email_verified_at=NOW(),auth_version=auth_version+1 WHERE id=?',[$pending['email'],$u['id']]);
            query($db,'DELETE FROM email_verifications WHERE user_id=?',[$u['id']]);
            query($db,'DELETE FROM password_resets WHERE user_id=?',[$u['id']]);
            signIn((int)$u['id'],true,$pending['version']+1);
            audit($db,null,(int)$u['id'],'Changed organizer email after verifying the new inbox; previous sessions revoked.');
        } elseif ($action==='change_password') {
            $account=one($db,'SELECT password_hash,auth_version FROM users WHERE id=?',[$u['id']]);
            $current=passwordValue($d,'current_password');
            demand(password_verify($current,$_SESSION['recovery_password_hash']??$account['password_hash']),'Current password is incorrect.',403);
            $password=passwordValue($d,'new_password'); strongPassword($password);
            demand($password===passwordValue($d,'confirm_password'),'New passwords must match.');
            demand($password!==$current && !password_verify($password,$account['password_hash']),'Choose a different new password.');
            query($db,'UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$u['id']]);
            query($db,'DELETE FROM password_resets WHERE user_id=?',[$u['id']]);
            signIn((int)$u['id'],$u['role']==='organizer',(int)$account['auth_version']+1);
            audit($db,null,(int)$u['id'],'Changed account password and revoked previous sessions.');
        } elseif ($action==='create_event') {
            admin($u); $roles=array_values(array_unique(array_filter(array_map('trim',explode(',',field($d,'roles',500))))));
            demand(count($roles)>0 && count($roles)<=20 && max(array_map('mb_strlen',$roles))<=60,'Enter 1–20 roles, up to 60 characters each.');
            demand(in_array($d['formation']??'', ['captain','free','random'],true),'Choose a team formation mode.'); demand(in_array($d['format']??'', ['single','double'],true),'Choose a tournament format.');
            $deadline=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',(string)($d['deadline']??'')); demand($deadline && $deadline->format('Y-m-d\TH:i')===$d['deadline'] && $deadline->getTimestamp()>time(),'Registration deadline must be a valid future date (Manila time).');
            query($db,'INSERT INTO events(name,game,team_size,max_teams,formation,format,race_to,deadline,roles,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)',[field($d,'name'),field($d,'game',100),number($d,'team_size',1,10),number($d,'max_teams',2,32),$d['formation'],$d['format'],number($d,'race_to',1,5),$deadline->format('Y-m-d H:i:s'),json_encode($roles),$u['id']]);
            $eid=(int)$db->lastInsertId(); audit($db,$eid,(int)$u['id'],'Created event and published competition rules.');
        } elseif ($action==='set_disabled') {
            admin($u); reauthenticate($db,$u,$d); $uid=number($d,'user_id',1,PHP_INT_MAX);
            demand($uid!==(int)$u['id'],'You cannot disable your own admin account.');
            demand(isset($d['disabled']) && is_bool($d['disabled']),'Choose an account status.');
            demand((bool)one($db,'SELECT id FROM users WHERE id=?',[$uid]),'Account not found.');
            query($db,'UPDATE users SET disabled_at=?,auth_version=auth_version+1 WHERE id=?',[$d['disabled']?date('Y-m-d H:i:s'):null,$uid]);
            audit($db,null,(int)$u['id'],($d['disabled']?'Disabled':'Enabled')." account #$uid.");
        } elseif ($action==='set_role') {
            admin($u); reauthenticate($db,$u,$d);
            admin($u); $uid=number($d,'user_id',1,PHP_INT_MAX); demand($uid!==(int)$u['id'],'You cannot change your own organizer role.'); demand(in_array($d['role']??'', ['player','referee','organizer'],true),'Invalid permission.');
            demand((bool)one($db,'SELECT id FROM users WHERE id=?',[$uid]),'Account not found.'); query($db,'UPDATE users SET role=? WHERE id=?',[$d['role'],$uid]); audit($db,null,(int)$u['id'],"Changed account #$uid permission to {$d['role']}.");
        } else {
            $eid=number($d,'event_id',1,PHP_INT_MAX); $e=one($db,'SELECT * FROM events WHERE id=? FOR UPDATE',[$eid]); demand((bool)$e,'Event not found.'); $e['roles']=json_decode($e['roles'],true);
            $uid=(int)$u['id'];
            switch($action) {
                case 'edit_event':
                    admin($u);
                    $deadline=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',field($d,'deadline',16));
                    demand($deadline && $deadline->format('Y-m-d\TH:i')===$d['deadline'],'Enter a valid registration deadline.');
                    query($db,'UPDATE events SET name=?,game=?,deadline=? WHERE id=?',[field($d,'name'),field($d,'game',100),$deadline->format('Y-m-d H:i:s'),$eid]);
                    audit($db,$eid,$uid,'Updated event name, game and registration deadline. Competition rules preserved.'); break;
                case 'delete_event':
                    admin($u); reauthenticate($db,$u,$d);
                    demand(($d['confirmation']??'')===$e['name'],'Type the exact event name to delete it.');
                    $reason=field($d,'reason',300);
                    query($db,'DELETE FROM matches WHERE event_id=?',[$eid]);
                    query($db,'DELETE FROM teams WHERE event_id=?',[$eid]);
                    audit($db,null,$uid,"Deleted event #$eid ({$e['name']}). Reason: $reason");
                    query($db,'DELETE FROM events WHERE id=?',[$eid]); break;
                case 'register_player':
                    demand($u['role']!=='organizer','Use a separate player account to participate.',403);
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
    $response=['ok'=>true,'created_event_id'=>$eid??null,'state'=>state($db,currentUser($db))];
    session_write_close(); echo json_encode($response);
} catch (DomainException $e) {
    if (isset($db)&&$db->inTransaction()) $db->rollBack(); if (http_response_code()<400) http_response_code(422); echo json_encode(['error'=>$e->getMessage()]);
} catch (PDOException $e) {
    if (isset($db)&&$db->inTransaction()) $db->rollBack();
    $duplicate=$e->getCode()==='23000';
    $schemaRequired=in_array((int)($e->errorInfo[1]??0),[1146,1054],true);
    $reference=bin2hex(random_bytes(8));
    http_response_code($duplicate?409:503);
    error_log('TFv1 database ['.$reference.'] stage='.($databaseStage??'unknown').' code='.databaseFailureCode($e).': '.$e->getMessage());
    $message=$duplicate?'That email, game ID, team name, registration or request already exists.':'The service is temporarily unavailable. Contact the administrator.';
    if ($schemaRequired) $message=setupAllowed()?'The database needs an update. Run php migrate.php from the TFv1 folder, then try again. Existing tournament data will be preserved.':'The service needs a database update. Please contact the administrator.';
    echo json_encode(['error'=>$message,'database_unavailable'=>!$duplicate,'schema_required'=>$schemaRequired,'code'=>$duplicate?'duplicate_record':databaseFailureCode($e),'reference'=>$reference]);
} catch (ServiceConfigurationException $e) {
    http_response_code(503);
    error_log('TFv1 configuration: '.$e->getMessage());
    echo json_encode(['error'=>'The server setup is incomplete. Please contact the organizer.','code'=>'server_configuration']);
} catch (Throwable $e) {
    if (isset($db)&&$db->inTransaction()) $db->rollBack(); http_response_code(500); error_log('TFv1: '.$e->getMessage()); echo json_encode(['error'=>'The server could not complete the request. Please try again later or contact the organizer.']);
} finally {
    if (isset($db)) { try { query($db,"SELECT RELEASE_LOCK('tfv1_mutation')"); } catch (Throwable $ignored) {} }
}
