<?php
// community.php → index.php is now the landing/community page
require_once __DIR__ . '/auth.php';
header('Location: index.php');
exit;
