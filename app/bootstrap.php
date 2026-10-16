<?php
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));
spl_autoload_register(static function(string $class):void{$prefix='AcePortal\\';if(strncmp($class,$prefix,strlen($prefix))!==0)return;$path=APP_ROOT.'/app/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($path))require $path;});
if(session_status()!==PHP_SESSION_ACTIVE){$secure=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off');session_set_cookie_params(['httponly'=>true,'secure'=>$secure,'samesite'=>'Lax','path'=>'/']);session_start();}
date_default_timezone_set('UTC');
function configFile(string $file):array{$path=APP_ROOT.'/config/'.$file.'.php';if(!is_file($path))throw new RuntimeException("Missing config file: config/{$file}.php");$data=require $path;if(!is_array($data))throw new RuntimeException("Invalid config: {$file}");return $data;}
function db():PDO{static $pdo;if(!$pdo instanceof PDO){$c=configFile('database');$dsn=sprintf('mysql:host=%s;dbname=%s;charset=%s',$c['host'],$c['database'],$c['charset']??'utf8mb4');$pdo=new PDO($dsn,$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}return $pdo;}
function e(?string $v):string{return htmlspecialchars($v??'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function redirect(string $p):never{header('Location: '.$p,true,303);exit;}
function flash(string $t,string $m):void{$_SESSION['_flash']=['type'=>$t,'message'=>$m];}
function getFlash():?array{$f=$_SESSION['_flash']??null;unset($_SESSION['_flash']);return is_array($f)?$f:null;}
function csrfToken():string{if(empty($_SESSION['_csrf']))$_SESSION['_csrf']=bin2hex(random_bytes(32));return $_SESSION['_csrf'];}
function verifyCsrf():void{$s=(string)($_POST['_csrf']??'');if($s===''||!hash_equals(csrfToken(),$s)){http_response_code(419);exit('Session expired. Reload the form and try again.');}}
function sendVerificationEmail(string $to,string $code):bool{$c=configFile('mail');$from=$c['from_email']??'no-reply@example.com';$name=$c['from_name']??'Ace Insurance Portal';$subject='Your Ace Insurance Portal verification code';$body="Your verification code is: {$code}\n\nThis code expires in 15 minutes. If you did not request access, ignore this email.";$headers=['From: '.sprintf('%s <%s>',$name,$from),'Content-Type: text/plain; charset=UTF-8','X-Mailer: PHP/'.PHP_VERSION];return mail($to,$subject,$body,implode("\r\n",$headers));}
function audit(string $action,string $type,?int $id,string $summary):void{$u=AcePortal\Auth::user();$q=db()->prepare('INSERT INTO audit_logs(actor_user_id,actor_role,action,entity_type,entity_id,summary,ip_address,user_agent) VALUES(:actor,:role,:action,:type,:entity,:summary,:ip,:ua)');$q->execute(['actor'=>$u['id']??null,'role'=>$u['role']??null,'action'=>$action,'type'=>$type,'entity'=>$id,'summary'=>mb_substr($summary,0,500),'ip'=>substr((string)($_SERVER['REMOTE_ADDR']??''),0,45)?:null,'ua'=>substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500)?:null]);}
