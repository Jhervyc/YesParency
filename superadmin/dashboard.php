<?php
include ("../config/db_connect.php");

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

if (!isset($_SESSION['role']) ||$_SESSION['role'] != 'superadmin') {
    header("Location: ../login.php");
    exit();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>
<body>
    Hello <?php echo $_SESSION["username"]."<br>"; ?> 
    <a href="../logout.php">Logout</a>
</body>
</html>