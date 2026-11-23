<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$checks=0;
foreach(range(2,32) as $n) foreach([false,true] as $double) {
    $schedule=roundRobin(range(1,$n),$double); check(count($schedule)===$n*($n-1)/2*($double?2:1),'Match count failed.');
    $roundTeams=[]; $pairs=[];
    foreach($schedule as [$r,$a,$b]) {
        check($a!==$b,'Self match.'); check(!isset($roundTeams[$r][$a])&&!isset($roundTeams[$r][$b]),'Team scheduled twice in a round.');
        $roundTeams[$r][$a]=$roundTeams[$r][$b]=true;
        $pair=[min($a,$b),max($a,$b)]; $key=implode('-',$pair); $pairs[$key]=($pairs[$key]??0)+1;
    }
    check(count($pairs)===$n*($n-1)/2,'Missing opponent.'); foreach($pairs as $count) check($count===($double?2:1),'Wrong pair frequency.');
    if($double) { $directed=[]; foreach($schedule as [$r,$a,$b]) { $key="$a-$b"; check(!isset($directed[$key]),'Double round robin did not reverse the match.'); $directed[$key]=true; } }
    $checks++;
}
$teams=[['id'=>1,'name'=>'Alpha','color'=>'#a78bfa'],['id'=>2,'name'=>'Beta','color'=>'#fb7185'],['id'=>3,'name'=>'Gamma','color'=>'#38bdf8']];
$m=fn($a,$b,$sa,$sb,$status='confirmed')=>['team_a'=>$a,'team_b'=>$b,'score_a'=>$sa,'score_b'=>$sb,'status'=>$status];
$table=standings($teams,[$m(1,2,2,0,'pending')]); check(array_sum(array_column($table,'points'))===0,'Pending scores counted.');
$table=standings($teams,[$m(1,2,2,1),$m(2,3,2,0)]); check($table[0]['team_id']===1,'Two-way head-to-head should beat game differential.');
$table=standings($teams,[$m(1,2,2,1),$m(2,3,2,1),$m(3,1,2,1)]); check(array_column($table,'rank')===[1,1,1],'Unresolved three-way ties must share rank.');
$table=standings($teams,[$m(1,2,2,0),$m(2,3,2,1),$m(3,1,2,1)]); check(array_column($table,'team_id')===[1,3,2],'Three-way tie must use game differential.');
echo "PASS: $checks round-robin combinations (2–32 teams, single/double), byes, reverse fixtures, pending scores and tiebreaks.\n";
