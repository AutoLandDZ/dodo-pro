<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
function reply(int $code, array $data): never { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
if ($_SERVER['REQUEST_METHOD']!=='POST') reply(405,['ok'=>false,'message'=>'طريقة الطلب غير مدعومة.']);
$csrf=$_SESSION['csrf_token']??null;$submittedCsrf=$_POST['csrf_token']??null;
if (!is_string($csrf)||$csrf===''||!is_string($submittedCsrf)||$submittedCsrf===''||!hash_equals($csrf,$submittedCsrf)) reply(400,['ok'=>false,'message'=>'انتهت صلاحية النموذج. حدّث الصفحة وحاول مجدداً.']);
if(trim((string)($_POST['website']??''))!=='')reply(422,['ok'=>false,'message'=>'تعذر إرسال الطلب.']);
$now=time();$recent=array_values(array_filter($_SESSION['order_submissions']??[],static fn($timestamp)=>is_int($timestamp)&&$timestamp>$now-600));if(count($recent)>=5)reply(429,['ok'=>false,'message'=>'تم إرسال عدة طلبات مؤخراً. انتظر قليلاً قبل إعادة المحاولة.']);
$name=trim((string)($_POST['name']??'')); $phone=trim((string)($_POST['phone']??'')); $note=trim((string)($_POST['note']??''));
$cart=json_decode((string)($_POST['cart']??''),true);
if ($name===''||mb_strlen($name)>120||!preg_match('/^[0-9+() .-]{7,40}$/',$phone)||mb_strlen($note)>1000||!is_array($cart)||count($cart)<1||count($cart)>20) reply(422,['ok'=>false,'message'=>'أكمل الاسم والهاتف واختر منتجاً واحداً على الأقل.']);
$items=[]; foreach($cart as $entry){$id=(int)($entry['id']??0);$qty=(int)($entry['quantity']??0); if($id>0&&$qty>0&&$qty<=10)$items[$id]=min(10,($items[$id]??0)+$qty);}
if (!$items||count($items)>20) reply(422,['ok'=>false,'message'=>'راجع المنتجات والكميات المختارة.']);
$marks=implode(',',array_fill(0,count($items),'?'));
$q=$pdo->prepare("SELECT id,name,price FROM products WHERE active=1 AND id IN ($marks)"); $q->execute(array_keys($items)); $found=$q->fetchAll();
if(count($found)!==count($items)) reply(422,['ok'=>false,'message'=>'تغيّر أحد المنتجات. حدّث الصفحة ثم أعد الطلب.']);
$total=0.0; $quote=false; foreach($found as $p){if($p['price']===null)$quote=true;else $total+=(float)$p['price']*$items[(int)$p['id']];}
try{$pdo->beginTransaction();$code='AL-'.date('ymd').'-'.strtoupper(bin2hex(random_bytes(3)));$q=$pdo->prepare('INSERT INTO orders(order_code,customer_name,phone,note,total,quote_required,status) VALUES(?,?,?,?,?,?,?)');$q->execute([$code,$name,$phone,$note,$total,$quote?1:0,'new']);$customer=$pdo->prepare("INSERT INTO customers(phone,name,updated_at) VALUES(?,?,CURRENT_TIMESTAMP) ON CONFLICT(phone) DO UPDATE SET name=excluded.name,updated_at=CURRENT_TIMESTAMP");$customer->execute([$phone,$name]);$orderId=(int)$pdo->lastInsertId();$line=$pdo->prepare('INSERT INTO order_items(order_id,product_id,product_name,quantity,unit_price) VALUES(?,?,?,?,?)');$summary=[];foreach($found as $p){$qty=$items[(int)$p['id']];$line->execute([$orderId,$p['id'],$p['name'],$qty,$p['price']]);$summary[]=$p['name'].' × '.$qty;}$pdo->commit();}
catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();reply(500,['ok'=>false,'message'=>'تعذر حفظ الطلب حالياً. حاول مجدداً أو تواصل معنا مباشرة.']);}
$_SESSION['order_submissions']=array_merge($recent,[$now]);
$config=require __DIR__.'/config.php'; $siteSettings=settings($pdo);$config['whatsapp']=$siteSettings['whatsapp'];$message="السلام عليكم AutoLand، أود تأكيد الطلب {$code}: ".implode('، ',$summary).". الاسم: {$name}. الهاتف: {$phone}.".($note!==''?" ملاحظة: {$note}.":'');
reply(200,['ok'=>true,'code'=>$code,'whatsapp'=>'https://wa.me/'.preg_replace('/\D+/','',$config['whatsapp']).'?text='.rawurlencode($message),'quote'=>$quote]);
