<?php 
    include ("utils/protect-page.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin</title>
</head>
<body>
    Hello <?php echo $_SESSION["username"]."<br>"; ?> 
    <?php  include("utils/side-nav.html") ?>
    
</body>
</html>