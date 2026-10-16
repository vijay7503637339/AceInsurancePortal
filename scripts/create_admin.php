<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only.\n");}
if($argc!==4){fwrite(STDERR,"Usage: php scripts/create_admin.php \"Full Name\" admin@example.com \"Strong-Password\"\n");exit(1);}
[, $name, $email, $password]=$argv;$name=trim($name);$email=mb_strtolower(trim($email));
if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<12){fwrite(STDERR,"Provide a name, valid email, and password of at least 12 characters.\n");exit(1);}
$q=db()->prepare("INSERT INTO users(role,full_name,email,password_hash) VALUES('admin',:name,:email,:hash)");
$q->execute(['name'=>$name,'email'=>$email,'hash'=>password_hash($password,PASSWORD_DEFAULT)]);
fwrite(STDOUT,"Admin created: {$email}\n");
