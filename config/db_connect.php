<?php
    require_once __DIR__ . '/../bootstrap.php';

    $db_host     = $_ENV['DB_HOST'] ?? 'localhost';
    $db_user     = $_ENV['DB_USER'] ?? 'root';
    $db_password = $_ENV['DB_PASS'] ?? '';
    $db_name     = $_ENV['DB_NAME'] ?? 'yesparency_db';
    $conn = "";

    try {
        $conn = mysqli_connect($db_host, $db_user, $db_password, $db_name);

    }catch(\mysqli_sql_exception $e){
        echo "can't connect: " . $e->getMessage();
    }
