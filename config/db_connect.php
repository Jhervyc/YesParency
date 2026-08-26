<?php
    $db_host     = 'localhost';
    $db_user     = 'root';
    $db_password = '';
    $db_name     = 'yesparency_db';
    $conn = "";

    try {
        $conn = mysqli_connect($db_host, $db_user, $db_password, $db_name);

    }catch(mysqli_sql_exception){
        echo "can't connect";
    }