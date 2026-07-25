<?php require_once "db.php"; 
$r = $pdo->query("SHOW COLUMNS FROM events LIKE 'status'")->fetch();
echo $r["Type"]."
";
$r2 = $pdo->query("SHOW COLUMNS FROM funeral_announcements LIKE 'status'")->fetch();
echo $r2["Type"]."
";
?>