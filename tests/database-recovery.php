<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/lib.php';
final class RecoveryTestPDO extends PDO { public function __construct() {} }
function failure(int $code): PDOException {
    $error=new PDOException('Fixture error'); $error->errorInfo=['HY000',$code,'Fixture error']; return $error;
}
$connected=new RecoveryTestPDO();
foreach ([2002=>'database_connection',1040=>'database_busy',1045=>'database_permissions',1146=>'database_schema',1049=>'database_name',2026=>'database_tls',9999=>'database_unavailable'] as $driverCode=>$category) {
    if (databaseFailureCode(failure($driverCode))!==$category) throw new RuntimeException('Incorrect diagnostic category.');
}
foreach ([2002,2003,2006,2013,2055,1040,1203,1226] as $code) {
    $attempts=0;
    $result=connectDatabase(function() use (&$attempts,$code,$connected): PDO { if (++$attempts===1) throw failure($code); return $connected; });
    if ($result!==$connected || $attempts!==2) throw new RuntimeException('Transient connection recovery failed.');
}
foreach ([1044,1045,1049,1054,1062,1142,1146,2026] as $code) {
    $attempts=0; $expected=failure($code);
    try { connectDatabase(function() use (&$attempts,$expected): PDO { ++$attempts; throw $expected; }); throw new RuntimeException('Expected failure.'); }
    catch (PDOException $error) { if ($error!==$expected || $attempts!==1) throw new RuntimeException('Permanent errors must not retry.'); }
}
$attempts=0;
try { connectDatabase(function() use (&$attempts): PDO { ++$attempts; throw failure(2002); }); throw new RuntimeException('Expected outage.'); }
catch (PDOException $error) { if ($attempts!==2) throw new RuntimeException('Retries must be bounded.'); }
$attempts=0;
$result=connectDatabase(function() use (&$attempts,$connected): PDO { ++$attempts; return $connected; });
if ($attempts!==1 || $result!==$connected) throw new RuntimeException('Healthy connections must run once.');
echo "PASS: transient connection recovery, bounded retries, permanent failures and healthy connections. No queries or writes replayed.\n";
