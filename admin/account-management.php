<?php 
include ("utils/protect-page.php");
?>

<style>
    #account {
        color: yellow;
    }

    .bidder-card{
        border:1px solid #ccc;
        padding:10px;
        margin-bottom:10px;
    }

    #sideModal{
        position:fixed;
        top:0;
        right:-500px;
        width:500px;
        height:100%;
        background:white;
        border-left:1px solid black;
        overflow-y:auto;
        transition:0.3s;
        padding:20px;
        box-sizing:border-box;
        z-index:1000;
    }

    #sideModal.active{
        right:0;
    }

    #closeBtn{
        float:right;
        cursor:pointer;
    }
</style>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin</title>
</head>
<body>

    Hello <?php echo $_SESSION["username"]."<br>"; ?>

    <?php include("utils/side-nav.html") ?>

    <h2>Bidders Account</h2>

    <?php

    $stmt = $conn->prepare(
        "SELECT *
        FROM users
        WHERE role = ? || (role = ? && status = ?)"
    );

    $role = "bidder";
    $role2 = "user";
    $status = "pending";

    $stmt->bind_param("sss", $role, $role2, $status);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $userId = $row['user_id'];

        $profileStmt = $conn->prepare("
            SELECT business_name
            FROM bidder_profiles
            WHERE user_id = ?
        ");

        $profileStmt->bind_param("i", $userId);
        $profileStmt->execute();

        $profileResult = $profileStmt->get_result();

        $businessName = "No Business Profile";

        if($profileResult->num_rows > 0){
            $profile = $profileResult->fetch_assoc();
            $businessName = $profile['business_name'];
        }

        echo "<div class='bidder-card'>";

        echo "<strong>";
        echo $row['firstname'] . " " . $row['lastname'];
        echo "</strong><br>";

        echo "Role: " . $row['role'] . "<br>";

        echo "Status: " . $row['status'] . "<br>";

        echo "Business Name: " . $businessName . "<br><br>";

        echo "
        <button onclick='loadBidder($userId)'>
            View More
        </button>
        ";

        echo "</div>";
    }

    ?>

    <div id="sideModal">

        <button id="closeBtn" onclick="closeModal()">
            X
        </button>

        <div id="modalContent">
            Select a bidder to view details.
        </div>

    </div>

<script>

function loadBidder(userId){

    fetch("get-bidder.php?id=" + userId)

    .then(response => response.text())

    .then(data => {

        document.getElementById("modalContent").innerHTML = data;

        document.getElementById("sideModal")
            .classList.add("active");

    })

    .catch(error => {

        document.getElementById("modalContent")
            .innerHTML = "Failed to load bidder details.";

    });

}

function closeModal(){

    document.getElementById("sideModal")
        .classList.remove("active");

}

</script>

</body>
</html>