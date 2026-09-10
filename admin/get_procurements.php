<?php
include("utils/protect-page.php");
include("utils/protect-secretariat.php");

header('Content-Type: application/json');

$status = isset($_GET['status']) ? trim($_GET['status']) : 'all';

if ($status !== 'all') {
    $stmt = mysqli_prepare($conn, "
        SELECT id, title, slsu_ref_no, abc, procurement_mode, status 
        FROM procurements 
        WHERE status = ? 
        ORDER BY id DESC
    ");
    mysqli_stmt_bind_param($stmt, "s", $status);
} else {
    $stmt = mysqli_prepare($conn, "
        SELECT id, title, slsu_ref_no, abc, procurement_mode, status 
        FROM procurements 
        ORDER BY id DESC
    ");
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$procurements = [];
while ($row = mysqli_fetch_assoc($result)) {
    $procurements[] = $row;
}

mysqli_stmt_close($stmt);

echo json_encode($procurements);
exit();
?>