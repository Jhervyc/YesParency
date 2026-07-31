<?php 
    include ("utils/protect-page.php");
    $userId = $_SESSION["user_id"];

    $stmt = $conn->prepare("
        SELECT application_status
        FROM bidder_profiles
        WHERE user_id = ?
    ");

    $stmt->bind_param("i", $userId);
    $stmt->execute();

    $result = $stmt->get_result();

    $hasApplication = false;
    $status = null;

    if($result->num_rows > 0){
        $hasApplication = true;

        $row = $result->fetch_assoc();
        $status = $row['application_status'];
    }
?>

<style>
    #register{
        color:gold;
    }
</style>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>
<body>
    Hello <?php echo $_SESSION["username"]."<br>"; ?> 

    <?php include("utils/side-nav.html") ?>
    <?php if(!$hasApplication): ?>
    <Fieldset>
        <legend>Register As Bidder</legend>
        <form action="<?php htmlspecialchars($_SERVER["PHP_SELF"]) ?>" method="post" enctype="multipart/form-data">
            Business Nature
            <hr>
            <label for="business-name">Registered Business Name</label>
            <input type="text" name="business-name"><br>

            <label for="philgeps-numnber">PhilGEPS Registration No. </label>
            <input type="text" name="philgeps-number"> <br>

            <label for="tin-number">TIN (Tax Identification No.)</label>
            <input type="text" name="tin-number"> <br>

            <label for="business-type">Business Type</label>
            <input type="text" name="business-type"><br>

            <label for="year">Year Established</label>
            <input type="number" name="year" placeholder="e.g. 2010" min="1900" max="2026"/><br>

            <label for="business-address">Registered Business Address </label>
            <input type="text" name="business-address"><br>

            <label for="business-email">Business Email</label>
            <input type="email" name="business-email" placeholder="info@company.com"/><br>

            <label for="business-phone">Business Phone</label>
            <input type="tel" name="business-phone" placeholder="+63 2 XXXX XXXX"/>

            <hr><br>
            Eligibility Documents
            <hr>
            DTI / SEC / CDA Registration Certificat <br>
            <input type="file" name="dti-sec-cda-certification" accept=".pdf,.jpg,.png"/> <br><br>
            Mayor's / Business Permit (current year) <br>
            <input type="file" name="mayor-business-permit" accept=".pdf,.jpg,.png"/> <br><br>

            BIR Certificate of Registration (Form 2303) <br>
            <input type="file" name="bir-certificate" accept=".pdf,.jpg,.png"/> <br><br>

            PhilGEPS Certificate of Registration <br>
            <input type="file" name="philgeps-certificate" accept=".pdf,.jpg,.png"/> <br><br>

            Valid Government-Issued ID of Authorized Representative <br>
            <input type="file" name="goverment-id" accept=".pdf,.jpg,.png"/> <br>
            <hr><br>

            Review & Declaration
            <hr>
            <input type="checkbox" name="c1"> Accuracy of Information <br>
            <input type="checkbox" name="c1"> PhilGEPS Registration Compliance <br>
            <input type="checkbox" name="c1"> No Conflict of Interest <br>
            <input type="checkbox" name="c1"> Terms of Use &amp; Privacy Policy <br>
            <input type="checkbox" name="c1"> Audit Trail Consent <br>
            <hr>

            <input type="submit" name="submit" value="submit">

        </form>
    </Fieldset>
    <?php else: ?>
        <?php if($status == 'pending'): ?>

            <h2>Application Submitted</h2>
            <p>
                Your bidder application is currently under review.
                Please wait for administrator approval.
            </p>

        <?php elseif($status == 'approved'): ?>

            <h2>Application Approved</h2>
            <p>
                You are now a verified bidder and may participate in bidding opportunities.
            </p>

        <?php elseif($status == 'rejected'): ?>

            <h2>Application Rejected</h2>
            <p>
                Your bidder application was not approved.
                Please contact the administrator for details.
            </p>

        <?php endif; ?>
    <?php endif; ?>
</body>
</html>

<?php 
    foreach ($_POST as $key => $value) {
        // Sanitize the value to prevent XSS attacks
        $clean_value = htmlspecialchars($value);
        
        echo "$key : $clean_value <br>";
    }  

    if($_SERVER["REQUEST_METHOD"] == "POST"){

        // checks if may bidders profile na, iwas bug 
        $check = $conn->prepare("
            SELECT profile_id
            FROM bidder_profiles
            WHERE user_id = ?
        ");

        $check->bind_param("i", $userId);
        $check->execute();
        
        if($check->get_result()->num_rows > 0){
            die("You already have a bidder profile.");
        }

        //creating bidders profile
        $businessName = $_POST['business-name'];
        $philgepsNumber = $_POST['philgeps-number'];
        $tinNumber = $_POST['tin-number'];
        $businessType = $_POST['business-type'];
        $year = $_POST['year'];
        $businessAddress = $_POST['business-address'];
        $businessEmail = $_POST['business-email'];
        $businessPhone = $_POST['business-phone'];

        $stmt = $conn->prepare("
            INSERT INTO bidder_profiles
            (
                user_id,
                business_name,
                philgeps_number,
                tin_number,
                business_type,
                year_established,
                business_address,
                business_email,
                business_phone
            )
            VALUES (?,?,?,?,?,?,?,?,?)
        ");

        $stmt->bind_param(
            "issssisss",
            $userId,
            $businessName,
            $philgepsNumber,
            $tinNumber,
            $businessType,
            $year,
            $businessAddress,
            $businessEmail,
            $businessPhone
        );

        $stmt->execute();

        //creating file path na paguuploadan
        $uploadDir = "../uploads/bidders/" . $userId . "/";

        if(!file_exists($uploadDir)){
            mkdir($uploadDir, 0777, true);
        }

        $documents = [
            'dti-sec-cda-certification' => 'dti_sec_cda',
            'mayor-business-permit' => 'mayor_permit',
            'bir-certificate' => 'bir_certificate',
            'philgeps-certificate' => 'philgeps_certificate',
            'goverment-id' => 'government_id'
        ];

        foreach($documents as $inputName => $documentType){

            if(isset($_FILES[$inputName]) && $_FILES[$inputName]['error'] == 0){
                $fileName = uniqid() . "_" . basename($_FILES[$inputName]['name']);

                $destination = $uploadDir . $fileName;

                if(move_uploaded_file( $_FILES[$inputName]['tmp_name'],  $destination )){
                    $stmt = $conn->prepare("
                        INSERT INTO bidder_documents
                        (
                            user_id,
                            document_type,
                            file_name,
                            file_path
                        )
                        VALUES (?,?,?,?)
                    ");

                    $stmt->bind_param(
                        "isss",
                        $userId,
                        $documentType,
                        $fileName,
                        $destination
                    );

                    $stmt->execute();
                }else{
                    die("something went wrong");
                }
            }else{
                die("All required documents must be uploaded.");
            }
        }
    //update users roie
    $stmt = $conn->prepare("
        UPDATE users
        SET status = 'pending'
        WHERE user_id = ?
    ");

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    header("Location: dashboard.php?application=submitted");
    exit();

    }

?>