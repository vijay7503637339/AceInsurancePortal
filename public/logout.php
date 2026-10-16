<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
AcePortal\Auth::logout();
redirect('/login.php');
