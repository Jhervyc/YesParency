<?php

include("utils/protect-page.php");

if(!isset($_GET['id'])){
    die("Invalid Request");
}

$userId = (int)$_GET['id'];

/*
|--------------------------------------------------------------------------
| USER + BIDDER PROFILE
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        u.*,
        bp.*
    FROM users u
    LEFT JOIN bidder_profiles bp
        ON u.user_id = bp.user_id
    WHERE u.user_id = ?
");

$stmt->bind_param("i", $userId);
$stmt->execute();

$result = $stmt->get_result();

if($result->num_rows == 0){
    die("User not found.");
}

$data = $result->fetch_assoc();

/*
|--------------------------------------------------------------------------
| OUTPUT
|--------------------------------------------------------------------------
*/

echo "<h2>";
echo htmlspecialchars($data['firstname']) . " ";
echo htmlspecialchars($data['lastname']);
echo "</h2>";

echo "<hr>";

echo "<b>Account Information</b><br>";

echo "Role: "
    . htmlspecialchars($data['role'])
    . "<br>";

echo "Status: "
    . htmlspecialchars($data['status'])
    . "<br>";

echo "Email: "
    . htmlspecialchars($data['email'])
    . "<br>";

echo "<br>";

if(!empty($data['business_name'])){

    echo "<b>Business Information</b><br>";

    echo "Business Name: "
        . htmlspecialchars($data['business_name'])
        . "<br>";

    echo "PhilGEPS Number: "
        . htmlspecialchars($data['philgeps_number'])
        . "<br>";

    echo "TIN Number: "
        . htmlspecialchars($data['tin_number'])
        . "<br>";

    echo "Business Type: "
        . htmlspecialchars($data['business_type'])
        . "<br>";

    echo "Year Established: "
        . htmlspecialchars($data['year_established'])
        . "<br>";

    echo "Business Address: "
        . htmlspecialchars($data['business_address'])
        . "<br>";

    echo "Business Email: "
        . htmlspecialchars($data['business_email'])
        . "<br>";

    echo "Business Phone: "
        . htmlspecialchars($data['business_phone'])
        . "<br>";

    echo "<br>";
}

/*
|--------------------------------------------------------------------------
| DOCUMENTS
|--------------------------------------------------------------------------
*/

echo "<b>Uploaded Documents</b><br>";

$docStmt = $conn->prepare("
    SELECT *
    FROM bidder_documents
    WHERE user_id = ?
");

$docStmt->bind_param("i", $userId);
$docStmt->execute();

$docResult = $docStmt->get_result();

if($docResult->num_rows == 0){

    echo "No documents uploaded.<br>";

}else{

    while($doc = $docResult->fetch_assoc()){

        echo htmlspecialchars($doc['document_type']);

        echo " - ";

        echo "<a href='../bidder/"
            . htmlspecialchars($doc['file_path'])
            . "' target='_blank'>";

        echo "View File";

        echo "</a>";

        echo "<br>";
    }
}

echo "<br><hr>";

/*
|--------------------------------------------------------------------------
| APPROVE / REJECT
|--------------------------------------------------------------------------
*/
$role = $data['role'];
if($role == 'user'){
    echo "
    <a href='approve_bidder.php?id=$userId'>
        Approve
    </a>
    ";

    echo " | ";

    echo "
    <a href='reject-bidder.php?id=$userId'>
        Reject
    </a>
    ";
}
?>