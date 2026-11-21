<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
if((int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn()>0){http_response_code(403);exit('حساب المدير موجود بالفعل. احذف create-admin.php من الاستضافة.');}
$error='';$done=false;
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();$u=trim((string)($_POST['username']??''));$p=(string)($_POST['password']??'');$confirm=(string)($_POST['confirm']??'');
 if(!preg_match('/^[A-Za-z0-9_.-]{3,40}$/',$u))$error='اسم المستخدم يجب أن يتكون من 3 إلى 40 حرفاً أو رقماً.';
 elseif(strlen($p)<16)$error='استخدم كلمة مرور من 16 حرفاً على الأقل.';
 elseif($p!==$confirm)$error='كلمتا المرور غير متطابقتين.';
 else{$q=$pdo->prepare('INSERT INTO admins(username,password_hash) VALUES(?,?)');$q->execute([$u,password_hash($p,PASSWORD_DEFAULT)]);$done=true;}
}
?><!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>إنشاء مدير AutoLand</title><link rel="stylesheet" href="assets/style.css"></head><body class="login-bg"><main class="login card"><img class="login-logo" src="assets/images/autoland-logo.png" alt="AutoLand"><h1>إنشاء حساب المدير</h1><?php if($done):?><p class="success">تم إنشاء الحساب. احذف ملف create-admin.php الآن، ثم افتح لوحة الإدارة.</p><?php else:?><p>هذه الخطوة متاحة مرة واحدة فقط.</p><?php if($error):?><p class="error"><?=e($error)?></p><?php endif;?><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><label>اسم المستخدم<input name="username" required minlength="3" maxlength="40" autocomplete="username"></label><label>كلمة المرور — 16 حرفاً على الأقل<input type="password" name="password" required minlength="16" autocomplete="new-password"></label><label>تأكيد كلمة المرور<input type="password" name="confirm" required minlength="16" autocomplete="new-password"></label><button class="btn yellow">إنشاء الحساب</button></form><?php endif;?></main></body></html>
