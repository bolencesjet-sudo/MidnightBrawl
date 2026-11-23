<?php
declare(strict_types=1);

function smtpConfig(): array {
    return [
        'host'=>envValue('SMTP_HOST','',['form_smpt_host','smpt_host']),
        'port'=>(int)envValue('SMTP_PORT','587',['smpt_port']),
        'username'=>envValue('SMTP_USERNAME','',['smpt_username']),
        'password'=>envValue('SMTP_PASSWORD','',['smpt_password']),
        'from'=>envValue('SMTP_FROM_EMAIL','',['smpt_from_email']),
        'name'=>envValue('SMTP_FROM_NAME','TFv1',['smpt_from_name']),
    ];
}
function sendVerification(PDO $db, int $uid, string $email): void {
    $key=envValue('TF_APP_KEY');
    demand(strlen($key)>=32,'Email verification is not configured. Contact the administrator.',503);
    $code=(string)random_int(100000,999999);
    query($db,'INSERT INTO email_verifications(user_id,code_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE)) ON DUPLICATE KEY UPDATE code_hash=VALUES(code_hash),expires_at=VALUES(expires_at)',[$uid,hash_hmac('sha256',$code,$key)]);
    require_once __DIR__.'/vendor/autoload.php';
    $c=smtpConfig();
    demand($c['host']!=='' && $c['username']!=='' && $c['password']!=='' && (bool)filter_var($c['from'],FILTER_VALIDATE_EMAIL) && $c['port']>0 && $c['port']<=65535,'Email delivery is not configured. Contact the administrator.',503);
    try {
        $mail=new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP(); $mail->Host=$c['host']; $mail->Port=$c['port']; $mail->SMTPAuth=true;
        $mail->Username=$c['username']; $mail->Password=$c['password'];
        $mail->SMTPSecure=$c['port']===465 ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPDebug=0; $mail->Timeout=10; $mail->CharSet='UTF-8';
        $mail->setFrom($c['from'],$c['name']); $mail->addAddress($email);
        $mail->Subject='Your TFv1 verification code';
        $mail->Body="Your registration verification code is: $code\n\nIt expires in 10 minutes. If you did not request this, ignore this email.";
        $mail->send();
    } catch (Throwable $e) {
        // Do not log transport exceptions: some providers include credentials in them.
        error_log('TFv1 verification email delivery failed.');
        demand(false,'Unable to send the verification email. Please try again later.',503);
    }
}
