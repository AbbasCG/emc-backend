<?php
$db = new PDO('sqlite:C:\Users\Administrator\Desktop\mikh\project-emc\emc-backend\database\database.sqlite');
$stmt = $db->query("SELECT id, name_ar, slug FROM departments");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
