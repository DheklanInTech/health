<?php
/** Sign out. */

declare(strict_types=1);

define('OLISA_ENTRY', true);
require __DIR__ . '/../_init.php';

use Olisa\Auth;
use Olisa\Util;

Auth::logout();
Util::redirect('login.php');
