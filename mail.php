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
    sendAccountEmail($email,'Your TFv1 verification code',"Your registration verification code is: $code\n\nIt expires in 10 minutes. If you did not request this, ignore this email.");
}
function sendTemporaryPassword(PDO $db, int $uid, string $email): void {
    $password=bin2hex(random_bytes(12));
    query($db,'INSERT INTO password_resets(user_id,password_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(), INTERVAL 15 MINUTE)) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash),expires_at=VALUES(expires_at)',[$uid,password_hash($password,PASSWORD_DEFAULT)]);
    sendAccountEmail($email,'Your TFv1 temporary password',"Your temporary password is: $password\n\nUse Player sign in with this email and temporary password within 15 minutes. It can be used once. Then open settings or your account menu and choose Change password. Enter this temporary password as your current password.\n\nYour existing password remains valid until you choose a new password. If you did not request this, ignore this email.");
}
function sendAccountEmail(string $email, string $subject, string $body): void {
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
        $mail->Subject=$subject;
        $mail->Body=$body;
        $mail->send();
    } catch (Throwable $e) {
        // Do not log transport exceptions: some providers include credentials in them.
        error_log('TFv1 account email delivery failed.');
        demand(false,'Unable to send the email. Please try again later.',503);
    }
}
