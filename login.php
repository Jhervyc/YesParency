<?php
    include "config/db_connect.php";
    session_start();

    if (isset($_SESSION['user_id'])) {
        switch($_SESSION["role"]){
            case "user":
                header("Location: user/dashboard.php");
                break;
            case "bidder":
                header("Location: bidder/dashboard.php");
                break;

            case "admin":
                header("Location: admin/dashboard.php");
                break;

            case "superadmin":
                header("Location: superadmin/dashboard.php");
                break;
        }
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
    <form action="<?php htmlspecialchars($_SERVER["PHP_SELF"]) ?>" method="post">
        <label for="username">Username: </label>
        <input type="text" name="username" required><br>
        <label for="password">Password: </label>
        <input type="password" name="password" required> <br>
        <input type="submit" name="login" value="login">
    </form>


    <br>
    Don't have an account yet? <a href="register.php">sign up</a>
    <br>
    <br>

</body>
</html>

<?php 
    if($_SERVER["REQUEST_METHOD"] == "POST"){
        $username = trim($_POST['username']);
        $password = $_POST['password'];

        $stmt = $conn->prepare(
            "SELECT *
            FROM users
            WHERE username = ?"
        );

        $stmt->bind_param("s", $username);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($result->num_rows == 1) {

            $user = $result->fetch_assoc();

            if (
                password_verify(
                    $password,
                    $user['password']
                )
            ) {

                // if ($user['status'] != 'active') {
                //     /// ++++++++++++++babaguhin ko pa to
                //     die("Account not approved.");
                // }

                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];

                switch($_SESSION["role"]){
                    case "user":
                        header("Location: user/dashboard.php");
                        break;
                    case "bidder":
                        header("Location: bidder/dashboard.php");
                        break;

                    case "admin":
                        header("Location: admin/dashboard.php");
                        break;

                    case "superadmin":
                        header("Location: superadmin/dashboard.php");
                        break;
                }
                exit();
            }
        }

        echo "Invalid username or password. <br>";

    }
    if(!empty($_SESSION)){
        echo "{$_SESSION["username"]} <br>";
        echo "<a href='logout.php'>Destroy</a>";
    }
    
?>