<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

function admin_go(): never { header('Location: admin.php'); exit; }
function admin_clear_pending(): void {
    unset($_SESSION['mfa_pending_id'],$_SESSION['mfa_pending_name'],$_SESSION['mfa_mode'],$_SESSION['mfa_setup_secret'],$_SESSION['email_pending_id'],$_SESSION['email_pending_name'],$_SESSION['email_code_hash'],$_SESSION['email_expires_at'],$_SESSION['email_sent_at'],$_SESSION['remember_requested']);
}
function admin_issue_remember_token(PDO $pdo,int $adminId):void{
    $now=time();$pdo->prepare('DELETE FROM admin_remember_tokens WHERE expires_at<?')->execute([$now]);
    $selector=bin2hex(random_bytes(12));$token=bin2hex(random_bytes(32));$expires=$now+1209600;
    $pdo->prepare('INSERT INTO admin_remember_tokens(admin_id,selector,token_hash,expires_at,created_at,last_used_at) VALUES(?,?,?,?,?,?)')->execute([$adminId,$selector,admin_token_hash($token),$expires,$now,$now]);
    $https=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off';setcookie(admin_remember_cookie_name(),$selector.'.'.$token,['expires'=>$expires,'path'=>admin_cookie_path(),'secure'=>$https,'httponly'=>true,'samesite'=>'Strict']);
}
function admin_try_remember_login(PDO $pdo):bool{
    $parts=admin_remember_cookie_value();if(!$parts)return false;[$selector,$token]=$parts;$now=time();
    $q=$pdo->prepare('SELECT t.id,t.admin_id,t.token_hash,t.expires_at,a.username FROM admin_remember_tokens t JOIN admins a ON a.id=t.admin_id WHERE t.selector=?');$q->execute([$selector]);$row=$q->fetch();
    if(!$row||(int)$row['expires_at']<$now||!hash_equals((string)$row['token_hash'],admin_token_hash($token))){if($row)$pdo->prepare('DELETE FROM admin_remember_tokens WHERE id=?')->execute([(int)$row['id']]);admin_expire_remember_cookie();return false;}
    $pdo->prepare('DELETE FROM admin_remember_tokens WHERE id=?')->execute([(int)$row['id']]);admin_issue_remember_token($pdo,(int)$row['admin_id']);session_regenerate_id(true);$_SESSION['admin_id']=(int)$row['admin_id'];$_SESSION['admin_name']=(string)$row['username'];return true;
}
function admin_finish(PDO $pdo,int $id,string $name,?array $recoveryCodes=null,bool $remember=false): never {
    session_regenerate_id(true);
    $_SESSION['admin_id']=$id; $_SESSION['admin_name']=$name;
    admin_clear_pending();
    if($recoveryCodes!==null) $_SESSION['recovery_codes_once']=$recoveryCodes;
    if($remember) admin_issue_remember_token($pdo,$id);
    header('Location: dashboard.php'); exit;
}
function admin_attempt(PDO $pdo,string $table,string $key,int $now): array|false {
    $pdo->prepare("DELETE FROM $table WHERE window_started<? AND blocked_until<?")->execute([$now-86400,$now]);
    $q=$pdo->prepare("SELECT attempts,window_started,blocked_until FROM $table WHERE login_key=?"); $q->execute([$key]); return $q->fetch();
}
function admin_fail(PDO $pdo,string $table,string $key,array|false $attempt,int $now): void {
    $window=$attempt&&(int)$attempt['window_started']>$now-900?(int)$attempt['window_started']:$now;
    $tries=$attempt&&(int)$attempt['window_started']>$now-900?(int)$attempt['attempts']+1:1;
    $blocked=$tries>=8?$now+900:0;
    $pdo->prepare("INSERT INTO $table(login_key,attempts,window_started,blocked_until) VALUES(?,?,?,?) ON CONFLICT(login_key) DO UPDATE SET attempts=excluded.attempts,window_started=excluded.window_started,blocked_until=excluded.blocked_until")->execute([$key,$tries,$window,$blocked]);
}
function admin_verification_email(): string {
    global $config;
    $email=trim((string)($config['admin_verification_email']??''));
    return filter_var($email,FILTER_VALIDATE_EMAIL)?$email:'';
}
function admin_email_mask(string $email): string {
    [$name,$domain]=array_pad(explode('@',$email,2),2,'');
    return mb_substr($name,0,1).'***@'.$domain;
}
function admin_mail_from(): string {
    global $config;
    $configured=trim((string)($config['mail_from']??''));
    if(filter_var($configured,FILTER_VALIDATE_EMAIL)) return $configured;
    $host=strtolower((string)($_SERVER['HTTP_HOST']??''));
    $host=preg_replace('/:\\d+$/','',$host)??'';
    $derived='noreply@'.$host;
    return filter_var($derived,FILTER_VALIDATE_EMAIL)?$derived:'';
}
function admin_send_email_code(string $code): bool {
    $to=admin_verification_email(); $from=admin_mail_from();
    if($to===''||$from===''||!function_exists('mail')) return false;
    $message="رمز تحقق AutoLand للوحة الإدارة: {$code}\r\n\r\nينتهي الرمز خلال 10 دقائق. لا تشاركه مع أي شخص.\r\nإذا لم تطلب تسجيل الدخول فتجاهل هذه الرسالة.";
    $headers=['MIME-Version'=>'1.0','Content-Type'=>'text/plain; charset=UTF-8','From'=>'AutoLand <'.$from.'>','Reply-To'=>$from,'X-Mailer'=>'AutoLand'];
    return @mail($to,'AutoLand admin verification code',wordwrap($message,70,"\r\n"),$headers);
}
function admin_start_email_challenge(int $id,string $name): bool {
    $code=(string)random_int(10000000,99999999);
    $_SESSION['email_pending_id']=$id; $_SESSION['email_pending_name']=$name;
    $_SESSION['email_code_hash']=password_hash($code,PASSWORD_DEFAULT);
    $_SESSION['email_expires_at']=time()+600; $_SESSION['email_sent_at']=time();
    return admin_send_email_code($code);
}
function admin_reset_token_from_request():?array{
    $value=(string)($_GET['reset']??'');$parts=explode('.',$value,2);
    if(count($parts)!==2||!preg_match('/^[a-f0-9]{24}$/D',$parts[0])||!preg_match('/^[a-f0-9]{64}$/D',$parts[1]))return null;
    return[$parts[0],$parts[1]];
}
function admin_send_password_reset_email(string $link):bool{
    $to=admin_verification_email();$from=admin_mail_from();
    if($to===''||$from===''||!function_exists('mail'))return false;
    $message="تم طلب إعادة تعيين كلمة مرور إدارة AutoLand.\r\n\r\nافتح هذا الرابط لإنشاء كلمة مرور جديدة خلال 20 دقيقة:\r\n{$link}\r\n\r\nإذا لم تطلب ذلك، تجاهل هذه الرسالة. لا تشارك هذا الرابط مع أي شخص.";
    $headers=['MIME-Version'=>'1.0','Content-Type'=>'text/plain; charset=UTF-8','From'=>'AutoLand <'.$from.'>','Reply-To'=>$from,'X-Mailer'=>'AutoLand'];
    return @mail($to,'AutoLand password reset',wordwrap($message,70,"\r\n"),$headers);
}
function admin_create_password_reset(PDO $pdo,array $admin):bool{
    $now=time();$pdo->prepare('DELETE FROM admin_password_resets WHERE expires_at<? OR used_at IS NOT NULL')->execute([$now]);
    $pdo->prepare('DELETE FROM admin_password_resets WHERE admin_id=?')->execute([(int)$admin['id']]);
    $selector=bin2hex(random_bytes(12));$token=bin2hex(random_bytes(32));$expires=$now+1200;
    $pdo->prepare('INSERT INTO admin_password_resets(admin_id,selector,token_hash,expires_at,created_at) VALUES(?,?,?,?,?)')->execute([(int)$admin['id'],$selector,admin_token_hash($token),$expires,$now]);
    return admin_send_password_reset_email(absolute_url('admin.php?reset='.$selector.'.'.$token));
}

$error=''; $mfaError=''; $emailError=''; $resetError=''; $resetNotice=''; $now=time(); $key=hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'unknown'));$resetToken=admin_reset_token_from_request();
if(empty($_SESSION['admin_id'])&&$resetToken===null&&!isset($_GET['forgot'])) admin_try_remember_login($pdo);
if(!empty($_SESSION['admin_id'])&&$resetToken===null&&!isset($_GET['forgot'])) { header('Location: dashboard.php'); exit; }

if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='password_reset_request'){
    verify_csrf();$attempt=admin_attempt($pdo,'admin_reset_attempts',$key,$now);
    if($attempt&&(int)$attempt['blocked_until']>$now)$resetNotice='إذا كان الطلب صالحاً، ستصل رسالة إلى بريد الإدارة بعد انتهاء مهلة الحماية.';
    else{$username=trim((string)($_POST['username']??''));$q=$pdo->prepare('SELECT id,username FROM admins WHERE username=?');$q->execute([$username]);$admin=$q->fetch();if($admin)admin_create_password_reset($pdo,$admin);admin_fail($pdo,'admin_reset_attempts',$key,$attempt,$now);$resetNotice='إذا كان اسم المستخدم صحيحاً، أرسلنا رابط إعادة التعيين إلى بريد الإدارة. الرابط صالح لمدة 20 دقيقة.';}
}

if($resetToken!==null&&$_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='password_reset_confirm'){
    verify_csrf();[$selector,$token]=$resetToken;$q=$pdo->prepare('SELECT r.id,r.admin_id,r.token_hash,r.expires_at,a.username FROM admin_password_resets r JOIN admins a ON a.id=r.admin_id WHERE r.selector=? AND r.used_at IS NULL');$q->execute([$selector]);$reset=$q->fetch();$password=(string)($_POST['password']??'');$confirm=(string)($_POST['confirm']??'');
    if(!$reset||(int)$reset['expires_at']<$now||!hash_equals((string)$reset['token_hash'],admin_token_hash($token)))$resetError='رابط إعادة التعيين غير صالح أو انتهت صلاحيته.';
    elseif(strlen($password)<16||$password!==$confirm)$resetError='استخدم كلمة مرور من 16 حرفاً على الأقل، وتأكد من تطابقها.';
    else{$pdo->beginTransaction();try{$pdo->prepare('UPDATE admins SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),(int)$reset['admin_id']]);$pdo->prepare('UPDATE admin_password_resets SET used_at=? WHERE id=?')->execute([$now,(int)$reset['id']]);$pdo->prepare('DELETE FROM admin_remember_tokens WHERE admin_id=?')->execute([(int)$reset['admin_id']]);$pdo->commit();admin_expire_remember_cookie();$_SESSION=[];session_regenerate_id(true);$_SESSION['auth_notice']='تم تغيير كلمة المرور. سجّل الدخول مجدداً باستخدام TOTP ورمز البريد.';admin_go();}catch(Throwable){if($pdo->inTransaction())$pdo->rollBack();$resetError='تعذر حفظ كلمة المرور الجديدة. أعد المحاولة.';}}
}

if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='login') {
    verify_csrf(); $attempt=admin_attempt($pdo,'admin_login_attempts',$key,$now);
    if($attempt&&(int)$attempt['blocked_until']>$now) $error='محاولات كثيرة. انتظر 15 دقيقة ثم أعد المحاولة.';
    else {
        $username=trim((string)($_POST['username']??'')); $password=(string)($_POST['password']??'');
        $q=$pdo->prepare('SELECT id,username,password_hash,totp_enabled FROM admins WHERE username=?'); $q->execute([$username]); $admin=$q->fetch();
        if($admin&&password_verify($password,$admin['password_hash'])) {
            $pdo->prepare('DELETE FROM admin_login_attempts WHERE login_key=?')->execute([$key]);
            session_regenerate_id(true); $_SESSION['mfa_pending_id']=(int)$admin['id']; $_SESSION['mfa_pending_name']=$admin['username'];$_SESSION['remember_requested']=isset($_POST['remember_device'])&&$_POST['remember_device']==='1';
            $_SESSION['mfa_mode']=(int)$admin['totp_enabled']===1?'verify':'setup';
            if((int)$admin['totp_enabled']!==1) $_SESSION['mfa_setup_secret']=base32_encode_secret(random_bytes(20));
            admin_go();
        }
        admin_fail($pdo,'admin_login_attempts',$key,$attempt,$now); $error='بيانات الدخول غير صحيحة.';
    }
}

if(!empty($_SESSION['mfa_pending_id'])&&$_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='cancel_mfa') {
    verify_csrf(); admin_clear_pending(); session_regenerate_id(true); admin_go();
}

if(!empty($_SESSION['mfa_pending_id'])&&$_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='mfa_verify') {
    verify_csrf(); $attempt=admin_attempt($pdo,'admin_mfa_attempts',$key,$now);
    if($attempt&&(int)$attempt['blocked_until']>$now) $mfaError='محاولات OTP كثيرة. انتظر 15 دقيقة ثم أعد المحاولة.';
    else {
        $q=$pdo->prepare('SELECT id,username,totp_secret_enc,totp_enabled,totp_recovery_hashes,totp_last_step FROM admins WHERE id=?'); $q->execute([(int)$_SESSION['mfa_pending_id']]); $admin=$q->fetch();
        $ok=false; $recoveryCodes=null;
        if(!$admin) { admin_clear_pending(); admin_go(); }
        if(($_SESSION['mfa_mode']??'')==='setup'&&(int)$admin['totp_enabled']!==1) {
            $secret=(string)($_SESSION['mfa_setup_secret']??''); $step=totp_match_step($secret,(string)($_POST['totp_code']??''),-1);
            if($step!==false) {
                try {
                    [$recoveryCodes,$hashes]=new_recovery_codes(); $cipher=totp_encrypt_secret($secret);
                    $q=$pdo->prepare('UPDATE admins SET totp_secret_enc=?,totp_enabled=1,totp_recovery_hashes=?,totp_last_step=? WHERE id=? AND totp_enabled=0');
                    $q->execute([$cipher,json_encode($hashes,JSON_UNESCAPED_SLASHES),$step,$admin['id']]); $ok=$q->rowCount()===1;
                } catch(Throwable) { $mfaError='تعذر حفظ إعداد OTP. تحقق من OpenSSL وصلاحية مجلد البيانات.'; }
            }
            if(!$ok&&$mfaError==='') $mfaError='رمز التحقق غير صحيح أو انتهت صلاحيته.';
        } elseif(($_SESSION['mfa_mode']??'')==='verify'&&(int)$admin['totp_enabled']===1) {
            $backup=strtoupper((string)preg_replace('/[^A-Fa-f0-9]/','',(string)($_POST['backup_code']??'')));
            if(strlen($backup)>=12) {
                $hashes=json_decode((string)$admin['totp_recovery_hashes'],true); $remaining=[]; $used=false;
                foreach(is_array($hashes)?$hashes:[] as $hash) { if(!$used&&is_string($hash)&&password_verify($backup,$hash)) $used=true; else $remaining[]=$hash; }
                if($used) { $pdo->prepare('UPDATE admins SET totp_recovery_hashes=? WHERE id=?')->execute([json_encode($remaining,JSON_UNESCAPED_SLASHES),$admin['id']]); $ok=true; }
            }
            if(!$ok) {
                try {
                    $secret=totp_decrypt_secret((string)$admin['totp_secret_enc']); $step=is_string($secret)?totp_match_step($secret,(string)($_POST['totp_code']??''),(int)$admin['totp_last_step']):false;
                    if($step!==false) { $q=$pdo->prepare('UPDATE admins SET totp_last_step=? WHERE id=? AND totp_last_step<?'); $q->execute([$step,$admin['id'],$step]); $ok=$q->rowCount()===1; }
                } catch(Throwable) { $ok=false; }
            }
            if(!$ok) $mfaError='رمز المصادقة أو رمز الاسترداد غير صحيح أو سبق استخدامه.';
        } else $mfaError='تعذر التحقق من إعداد المصادقة.';
        if($ok) {
            $pdo->prepare('DELETE FROM admin_mfa_attempts WHERE login_key=?')->execute([$key]);
            $id=(int)$admin['id']; $name=(string)$admin['username'];
            unset($_SESSION['mfa_pending_id'],$_SESSION['mfa_pending_name'],$_SESSION['mfa_mode'],$_SESSION['mfa_setup_secret']);
            $_SESSION['recovery_codes_pending']=$recoveryCodes;
            if(admin_start_email_challenge($id,$name)) admin_go();
            $emailError='تعذر إرسال رمز البريد. اضبط mail_from إلى بريد من نطاق الاستضافة وتحقق من إعداد إرسال البريد، ثم أعد الإرسال.';
        }
        if(!$ok) admin_fail($pdo,'admin_mfa_attempts',$key,$attempt,$now);
    }
}

if(!empty($_SESSION['email_pending_id'])&&$_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='email_resend') {
    verify_csrf();
    if($now-(int)($_SESSION['email_sent_at']??0)<60) $emailError='انتظر دقيقة واحدة قبل طلب رمز جديد.';
    elseif(admin_start_email_challenge((int)$_SESSION['email_pending_id'],(string)$_SESSION['email_pending_name'])) admin_go();
    else $emailError='تعذر إرسال رمز البريد. تحقق من إعداد mail_from وDNS للبريد في cPanel.';
}
if(!empty($_SESSION['email_pending_id'])&&$_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='email_cancel') {
    verify_csrf(); admin_clear_pending(); session_regenerate_id(true); admin_go();
}
if(!empty($_SESSION['email_pending_id'])&&$_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='email_verify') {
    verify_csrf(); $attempt=admin_attempt($pdo,'admin_email_attempts',$key,$now);
    if($attempt&&(int)$attempt['blocked_until']>$now) $emailError='محاولات كثيرة. انتظر 15 دقيقة ثم أعد المحاولة.';
    elseif($now>(int)($_SESSION['email_expires_at']??0)) $emailError='انتهت صلاحية الرمز. اطلب رمزاً جديداً.';
    else {
        $code=trim((string)($_POST['email_code']??'')); $hash=$_SESSION['email_code_hash']??'';
        if(preg_match('/^\\d{8}$/',$code)&&is_string($hash)&&password_verify($code,$hash)) {
            $pdo->prepare('DELETE FROM admin_email_attempts WHERE login_key=?')->execute([$key]);
            $recovery=$_SESSION['recovery_codes_pending']??null; unset($_SESSION['recovery_codes_pending']);
            $remember=(bool)($_SESSION['remember_requested']??false);unset($_SESSION['remember_requested']);admin_finish($pdo,(int)$_SESSION['email_pending_id'],(string)$_SESSION['email_pending_name'],is_array($recovery)?$recovery:null,$remember);
        }
        admin_fail($pdo,'admin_email_attempts',$key,$attempt,$now); $emailError='رمز البريد غير صحيح.';
    }
}

if($resetToken!==null){
    [$selector,$token]=$resetToken;$q=$pdo->prepare('SELECT token_hash,expires_at FROM admin_password_resets WHERE selector=? AND used_at IS NULL');$q->execute([$selector]);$activeReset=$q->fetch();$validReset=$activeReset&&(int)$activeReset['expires_at']>=$now&&hash_equals((string)$activeReset['token_hash'],admin_token_hash($token));
?><!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>إعادة تعيين كلمة المرور | AutoLand</title><link rel="stylesheet" href="assets/style.css"></head><body class="login-bg"><main class="login card"><img class="login-logo" src="assets/images/autoland-logo.png" alt="AutoLand"><h1>إنشاء كلمة مرور جديدة</h1><?php if(!$validReset):?><p class="error">رابط إعادة التعيين غير صالح أو انتهت صلاحيته.</p><a href="admin.php?forgot=1">طلب رابط جديد</a><?php else:?><p>اختر كلمة مرور قوية من 16 حرفاً على الأقل. سيتم إلغاء كل الأجهزة المتذكّرة بعد الحفظ.</p><?php if($resetError):?><p class="error"><?=e($resetError)?></p><?php endif;?><form method="post"><input type="hidden" name="action" value="password_reset_confirm"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><label>كلمة المرور الجديدة<div class="password-field"><input id="resetPassword" type="password" name="password" required minlength="16" autocomplete="new-password"><button class="password-toggle" type="button" data-password-toggle="resetPassword" aria-label="إظهار كلمة المرور" aria-pressed="false">◉</button></div></label><label>تأكيد كلمة المرور<div class="password-field"><input id="resetConfirm" type="password" name="confirm" required minlength="16" autocomplete="new-password"><button class="password-toggle" type="button" data-password-toggle="resetConfirm" aria-label="إظهار كلمة المرور" aria-pressed="false">◉</button></div></label><button class="btn yellow" type="submit">حفظ كلمة المرور الجديدة</button></form><?php endif;?></main><script src="assets/auth-ui.js"></script></body></html><?php exit;
}

if(isset($_GET['forgot'])){
?><!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>استرجاع كلمة المرور | AutoLand</title><link rel="stylesheet" href="assets/style.css"></head><body class="login-bg"><main class="login card"><img class="login-logo" src="assets/images/autoland-logo.png" alt="AutoLand"><h1>استرجاع كلمة المرور</h1><p>أدخل اسم مستخدم المدير. إذا كان صحيحاً سيصل رابط آمن إلى بريد الإدارة <b dir="ltr"><?=e(admin_email_mask(admin_verification_email()))?></b>.</p><?php if($resetNotice):?><p class="success"><?=e($resetNotice)?></p><?php endif;?><form method="post"><input type="hidden" name="action" value="password_reset_request"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><label>اسم المستخدم<input name="username" autocomplete="username" required maxlength="40"></label><button class="btn yellow" type="submit">إرسال رابط الاسترجاع</button></form><a href="admin.php">العودة لتسجيل الدخول</a></main></body></html><?php exit;
}

if(!empty($_SESSION['email_pending_id'])) {
    $recipient=admin_verification_email();
?><!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>تحقق البريد | AutoLand</title><link rel="stylesheet" href="assets/style.css"></head><body class="login-bg"><main class="login card"><img class="login-logo" src="assets/images/autoland-logo.png" alt="AutoLand"><h1>تحقق البريد الإلكتروني</h1><p>أرسلنا رمزاً من 8 أرقام إلى <b dir="ltr"><?=e(admin_email_mask($recipient))?></b>. الرمز صالح لمدة 10 دقائق.</p><?php if($emailError):?><p class="error"><?=e($emailError)?></p><?php endif;?><form method="post"><input type="hidden" name="action" value="email_verify"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><label>رمز البريد<input name="email_code" inputmode="numeric" pattern="[0-9]{8}" maxlength="8" autocomplete="one-time-code" required></label><button class="btn yellow" type="submit">تحقق والدخول</button></form><form method="post"><input type="hidden" name="action" value="email_resend"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><button class="btn outline" type="submit">إرسال رمز جديد</button></form><form method="post"><input type="hidden" name="action" value="email_cancel"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><button class="btn outline" type="submit">إلغاء</button></form></main></body></html><?php exit;
}

if(!empty($_SESSION['mfa_pending_id'])) {
    $mode=$_SESSION['mfa_mode']??'verify'; if($mode==='setup'&&empty($_SESSION['mfa_setup_secret'])) $_SESSION['mfa_setup_secret']=base32_encode_secret(random_bytes(20));
?><!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>OTP | AutoLand</title><link rel="stylesheet" href="assets/style.css"></head><body class="login-bg"><main class="login card"><img class="login-logo" src="assets/images/autoland-logo.png" alt="AutoLand"><h1><?=$mode==='setup'?'تفعيل التحقق بخطوتين':'رمز الأمان'?></h1><p>مرحباً <?=e($_SESSION['mfa_pending_name']??'المدير')?>. يلزم رمز OTP بعد كلمة المرور.</p><?php if($mfaError):?><p class="error"><?=e($mfaError)?></p><?php endif;?><?php if($mode==='setup'):?><p>أدخل المفتاح يدوياً في تطبيق مصادقة يدعم TOTP، ثم اكتب الرمز ذي 6 أرقام.</p><code class="totp-secret" dir="ltr"><?=e($_SESSION['mfa_setup_secret'])?></code><?php else:?><p>أدخل رمز التطبيق أو رمز استرداد أحادي الاستخدام.</p><?php endif;?><form method="post"><input type="hidden" name="action" value="mfa_verify"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><label>رمز التطبيق<input name="totp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" <?=$mode==='setup'?'required':''?>></label><?php if($mode!=='setup'):?><label>أو رمز الاسترداد<input name="backup_code" maxlength="20" autocomplete="off"></label><?php endif;?><button class="btn yellow" type="submit"><?=$mode==='setup'?'تفعيل والمتابعة':'تحقق'?></button></form><form method="post"><input type="hidden" name="action" value="cancel_mfa"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><button class="btn outline" type="submit">إلغاء</button></form></main></body></html><?php exit;
}
$authNotice=(string)($_SESSION['auth_notice']??'');unset($_SESSION['auth_notice']);?><!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>دخول الإدارة | AutoLand</title><link rel="stylesheet" href="assets/style.css"></head><body class="login-bg"><main class="login card"><img class="login-logo" src="assets/images/autoland-logo.png" alt="AutoLand"><h1>دخول لوحة التحكم</h1><?php if($authNotice):?><p class="success"><?=e($authNotice)?></p><?php endif;?><?php if($error):?><p class="error"><?=e($error)?></p><?php endif;?><form method="post"><input type="hidden" name="action" value="login"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><label>اسم المستخدم<input name="username" autocomplete="username" required maxlength="40"></label><label>كلمة المرور<div class="password-field"><input id="adminPassword" type="password" name="password" autocomplete="current-password" required><button class="password-toggle" type="button" data-password-toggle="adminPassword" aria-label="إظهار كلمة المرور" aria-pressed="false">◉</button></div></label><label class="remember-choice"><input type="checkbox" name="remember_device" value="1"> <span>تذكّر هذا الجهاز لمدة 14 يوماً</span></label><button class="btn yellow" type="submit">دخول الإدارة</button></form><div class="login-actions"><a href="admin.php?forgot=1">نسيت كلمة المرور؟</a><a href="index.php">العودة للمنصة</a></div><p class="muted">الحماية: كلمة مرور قوية + تطبيق المصادقة TOTP + رمز بريد إلكتروني. لا تفعّل «تذكّرني» إلا على جهازك الشخصي.</p></main><script src="assets/auth-ui.js"></script></body></html>
