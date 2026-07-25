<?php require_once "db.php";
foreach(["events","funeral_announcements"] as $t){
    $r=$pdo->query("SHOW COLUMNS FROM $t")->fetchAll();
    foreach($r as $c) if(str_contains($c["Field"],"feat")) echo $t.".".implode("|",array_values($c))."
";
}
$fp=$pdo->query("SHOW TABLES LIKE 'featured_%'")->fetchAll(PDO::FETCH_COLUMN);
foreach($fp as $t) echo "TABLE: $t
";
?>