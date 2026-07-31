<?php 
    include("utils/protect-page.php");

    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_procurement'])) {

        // 1. Sanitize and retrieve inputs
        $ref_no = isset($_POST['philgeps_ref_no']) ? trim($_POST['philgeps_ref_no']) : '';
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $abc = floatval($_POST['abc']);
        $mode = trim($_POST['procurement_mode']);
        
        // Handle optional dates cleanly (convert empty strings to NULL)
        $posting_date = !empty($_POST['posting_date']) ? $_POST['posting_date'] : NULL;
        $closing_date = !empty($_POST['closing_date']) ? $_POST['closing_date'] : NULL;
        $opening_date = !empty($_POST['opening_date']) ? $_POST['opening_date'] : NULL;

        $created_by = intval($_SESSION['user_id']);

        // 2. Check for duplicate PhilGEPS Ref No
        if (!empty($ref_no)) {
            $check_stmt = $conn->prepare("SELECT id FROM procurements WHERE philgeps_ref_no = ? LIMIT 1");
            $check_stmt->bind_param("s", $ref_no);
            $check_stmt->execute();
            $check_stmt->store_result();

            if ($check_stmt->num_rows > 0) {
                $check_stmt->close();
                $_SESSION['alert_error'] = "PhilGEPS Reference Number already exists!";
                header("Location: create_procurement.php?error=duplicate_ref");
                exit();
            }
            $check_stmt->close();
        }

        // 3. Insert into Database using Prepared Statements
        $stmt = $conn->prepare("
            INSERT INTO procurements
            (
                philgeps_ref_no,
                title,
                description,
                abc,
                procurement_mode,
                posting_date,
                closing_date,
                opening_date,
                created_by
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "sssdssssi",
            $ref_no,
            $title,
            $description,
            $abc,
            $mode,
            $posting_date,
            $closing_date,
            $opening_date,
            $created_by
        );

        if ($stmt->execute()) {
            $procurement_id = $conn->insert_id;
            $stmt->close();
            
            if (isset($_FILES['documents']) && !empty($_FILES['documents']['name'][0])) {
                
                $upload_dir = "../uploads/procurements/";

                // Create folder if it doesn't exist
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                $doc_stmt = $conn->prepare("
                    INSERT INTO procurement_documents (procurement_id, document_name, file_path) 
                    VALUES (?, ?, ?)
                ");

                foreach ($_FILES['documents']['name'] as $index => $original_name) {
                    $tmp_name = $_FILES['documents']['tmp_name'][$index];
                    $file_error = $_FILES['documents']['error'][$index];

                    if ($file_error === UPLOAD_ERR_OK) {
                        // Generate a unique filename to prevent overwriting existing files
                        $file_ext = pathinfo($original_name, PATHINFO_EXTENSION);
                        $unique_filename = time() . '_' . uniqid() . '.' . $file_ext;
                        $target_path = $upload_dir . $unique_filename;

                        if (move_uploaded_file($tmp_name, $target_path)) {
                            // Insert record into procurement_documents table
                            $doc_stmt->bind_param("iss", $procurement_id, $original_name, $target_path);
                            $doc_stmt->execute();
                        }
                    }
                }
                $doc_stmt->close();
            }

            header("Location: manage_lots.php?id=" . $procurement_id);
            exit();
        } else {
            $stmt->close();
            header("Location: create_procurement.php?error=insert_failed");
            exit();
        }
    }
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
    <div>
        <form action="create_procurement.php" method="POST" enctype="multipart/form-data">

            <h2>Create Procurement</h2>

            <hr>

            <h3>Procurement Information</h3>

            <div>
                <label for="philgeps_ref_no">PhilGEPS Reference Number</label><br>
                <input type="text" id="philgeps_ref_no" name="philgeps_ref_no" required>
            </div>

            <br>

            <div>
                <label for="title">Project Title</label><br>
                <input type="text" id="title" name="title" required>
            </div>

            <br>

            <div>
                <label for="description">Project Description</label><br>
                <textarea
                    id="description"
                    name="description"
                    rows="5"
                    cols="50"
                ></textarea>
            </div>

            <br>

            <div>
                <label for="abc">Approved Budget for the Contract (ABC)</label><br>
                <input
                    type="number"
                    step="0.01"
                    min="0"
                    id="abc"
                    name="abc"
                    required
                >
            </div>

            <br>

            <div>
                <label for="procurement_mode">Procurement Mode</label><br>
                <select id="procurement_mode" name="procurement_mode" required>
                    <option value="Public Bidding">Public Bidding</option>
                    <option value="Limited Source Bidding">Limited Source Bidding</option>
                    <option value="Direct Contracting">Direct Contracting</option>
                    <option value="Repeat Order">Repeat Order</option>
                    <option value="Shopping">Shopping</option>
                    <option value="Negotiated Procurement">Negotiated Procurement</option>
                </select>
            </div>

            <hr>

            <h3>Important Dates</h3>

            <div>
                <label for="posting_date">Posting Date</label><br>
                <input
                    type="date"
                    id="posting_date"
                    name="posting_date"
                    required
                >
            </div>

            <br>

            <div>
                <label for="closing_date">Bid Submission Deadline</label><br>
                <input
                    type="datetime-local"
                    id="closing_date"
                    name="closing_date"
                    required
                >
            </div>

            <br>

            <div>
                <label for="opening_date">Bid Opening Date</label><br>
                <input
                    type="datetime-local"
                    id="opening_date"
                    name="opening_date"
                    required
                >
            </div>

            <hr>

            <h3>Additional Documents</h3>
            
            <label for="documents">Upload Document(s)</label><br>
            <input type="file" id="documents" name="documents[]" multiple accept=".pdf,.doc,.docx,.zip">

            <br><br>

            <button type="submit" name="save_procurement">
                Save As Draft
            </button>

        </form>
        <a href="procurement.php">Cancel</a>
    </div>

    <!-- Alert Trigger for Duplicate Errors -->
    <?php if (isset($_SESSION['alert_error'])): ?>
        <script>
            alert(<?= json_encode($_SESSION['alert_error']); ?>);
        </script>
        <?php unset($_SESSION['alert_error']); ?>
    <?php endif; ?>
    
</body>
</html>

