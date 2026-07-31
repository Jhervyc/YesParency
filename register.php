<?php 
    include ("config/db_connect.php");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>
<body>
    <a href="login.php">login</a>
    <fieldset>
        <legend>Registration</legend>
        <form action="<?php htmlspecialchars($_SERVER["PHP_SELF"]) ?>" method="POST"> 
            <input type="text" name="firstname" placeholder="First Name" required><br>
            <input type="text" name="lastname" placeholder="Last Name" required><br>
            <input type="email" name="email" placeholder="Email" required><br>
            <input type="text" name="username" placeholder="Username" required><br>
            <input type="password" name="password" placeholder="Password" required><br>
            <input type="password" name="confirm_password" placeholder="Confirm Password" required><br>
            <button type="submit">Register</button>
        </form>
    </fieldset>
    
</body>
</html>

<?php 
    if($_SERVER["REQUEST_METHOD"] == "POST"){
        $firstname = trim($_POST['firstname']);
        $lastname = trim($_POST['lastname']);
        $email = trim($_POST['email']);
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $confirmPassword = $_POST['confirm_password'];
        $status = "active";

        if ($password !== $confirmPassword) {
            die("Passwords do not match.");
        }

        /* Check existing username/email */
        $check = $conn->prepare(
            "SELECT user_id FROM users
            WHERE username = ? OR email = ?"
        );

        $check->bind_param("ss", $username, $email);
        $check->execute();
        $result = $check->get_result();

        if ($result->num_rows > 0) {
            die("Username or Email already exists.");
        }

        /* Hash password */
        $hashedPassword = password_hash(
            $password,
            PASSWORD_DEFAULT
        );

        /* Insert user */
        $stmt = $conn->prepare(
            "INSERT INTO users
            (firstname, lastname, email, username, password)
            VALUES (?, ?, ?, ?, ?)"
        );

        $stmt->bind_param(
            "sssss",
            $firstname,
            $lastname,
            $email,
            $username,
            $hashedPassword
        );

        if ($stmt->execute()) {
            echo "Registration Successful!";
        } else {
            echo "Error: " . $stmt->error;
        }
    }

?>