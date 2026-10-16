<?php
declare(strict_types=1);
namespace AcePortal;
final class Auth {
 public static function user(): ?array { return $_SESSION['user'] ?? null; }
 public static function attempt(string $email, string $password, string $portal): bool {
  $q=\\db()->prepare('SELECT * FROM users WHERE email=:email AND is_active=1 LIMIT 1');
  $q->execute(['email'=>mb_strtolower(trim($email))]); $u=$q->fetch();
  $allowed=$portal==='agency' ? ['admin','staff'] : ['customer'];
  if(!$u || !in_array($u['role'],$allowed,true) || empty($u['password_hash']) || !password_verify($password,$u['password_hash'])) return false;
  session_regenerate_id(true);
  $_SESSION['user']=['id'=>(int)$u['id'],'role'=>$u['role'],'full_name'=>$u['full_name'],'email'=>$u['email'],'insured_id'=>$u['insured_id']!==null?(int)$u['insured_id']:null];
  \\db()->prepare('UPDATE users SET last_login_at=UTC_TIMESTAMP() WHERE id=:id')->execute(['id'=>$u['id']]);
  \\audit('login','user',(int)$u['id'],'Successful login to '.$portal.' portal');
  return true;
 }
 public static function requireRoles(array $roles): array {
  $u=self::user(); if(!$u) \\redirect('/login.php');
  if(!in_array($u['role'],$roles,true)){http_response_code(403);exit('Access denied.');}
  return $u;
 }
 public static function logout(): void {
  if(self::user()) \\audit('logout','user',(int)self::user()['id'],'User logged out');
  $_SESSION=[];
  if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']);}
  session_destroy();
 }
}
