<?php
/**
 * Bidder Document Helper Utilities for YesParency
 *
 * Centralizes document definitions, expiration evaluation, validation,
 * and physical file management.
 */

if (!defined('REQUIRED_BIDDER_DOCS')) {
    define('REQUIRED_BIDDER_DOCS', [
        'dti_sec_cda'          => 'DTI / SEC / CDA Certificate',
        'mayor_permit'         => "Mayor's / Business Permit",
        'bir_certificate'      => 'BIR Certificate (Form 2303)',
        'philgeps_certificate' => 'PhilGEPS Certificate of Registration',
        'government_id'        => 'Valid Government-Issued ID',
    ]);
}

/**
 * Fetch all documents uploaded by a specific user indexed by document_type.
 *
 * @param mysqli $conn
 * @param int $userId
 * @return array<string, array>
 */
function get_bidder_documents(mysqli $conn, int $userId): array
{
    $docs = [];
    $stmt = $conn->prepare("
        SELECT document_id, user_id, document_type, file_name, file_path, upload_date, expiration_date
        FROM bidder_documents
        WHERE user_id = ?
        ORDER BY upload_date ASC
    ");
    if (!$stmt) return $docs;

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $docs[$row['document_type']] = $row;
    }
    $stmt->close();

    return $docs;
}

/**
 * Evaluate the validity of a document based strictly on its expiration_date:
 *   expiration_date >= today -> Valid
 *   expiration_date < today  -> Expired
 *   NULL                     -> No expiration
 *
 * @param string|null $expirationDate
 * @return array
 */
function get_document_validity_info(?string $expirationDate): array
{
    if (empty($expirationDate) || $expirationDate === '0000-00-00') {
        return [
            'status'        => 'no_expiration',
            'is_valid'      => true,
            'is_expired'    => false,
            'label'         => 'No Expiration',
            'badge_class'   => 'badge-neutral',
            'badge_style'   => 'background:#f0f4f2; color:#384d40; border:1px solid #d8e2dc;',
            'date_formatted'=> 'No Expiration Date',
            'days_remaining'=> null,
        ];
    }

    $todayStr = date('Y-m-d');
    $expTime  = strtotime($expirationDate);
    $todayTime= strtotime($todayStr);
    $daysDiff = (int)round(($expTime - $todayTime) / 86400);

    if ($expirationDate >= $todayStr) {
        $urgency = ($daysDiff <= 7) ? 'urgent' : (($daysDiff <= 30) ? 'warning' : 'normal');
        $badgeStyle = 'background:#D9F2DF; color:#1f7a3d; border:1px solid #b7e4c2;';
        if ($urgency === 'urgent') {
            $badgeStyle = 'background:#FBE1E1; color:#c23b3b; border:1px solid #f5b7b7;';
        } elseif ($urgency === 'warning') {
            $badgeStyle = 'background:#FDF0CF; color:#97710a; border:1px solid #f6deb3;';
        }

        return [
            'status'        => 'valid',
            'is_valid'      => true,
            'is_expired'    => false,
            'urgency'       => $urgency,
            'label'         => 'Valid until ' . date('M j, Y', $expTime),
            'badge_class'   => 'badge-valid',
            'badge_style'   => $badgeStyle,
            'date_formatted'=> date('F j, Y', $expTime),
            'days_remaining'=> $daysDiff,
        ];
    }

    return [
        'status'        => 'expired',
        'is_valid'      => false,
        'is_expired'    => true,
        'urgency'       => 'expired',
        'label'         => 'Expired (' . date('M j, Y', $expTime) . ')',
        'badge_class'   => 'badge-expired',
        'badge_style'   => 'background:#FBE1E1; color:#c23b3b; border:1px solid #f5b7b7;',
        'date_formatted'=> date('F j, Y', $expTime),
        'days_remaining'=> $daysDiff,
    ];
}

/**
 * Check whether all required bidder documents are present and valid.
 * A bidder cannot submit a bid if any required document is missing or expired.
 *
 * @param mysqli $conn
 * @param int $userId
 * @return array
 */
function check_bidder_documents_status(mysqli $conn, int $userId): array
{
    $docs = get_bidder_documents($conn, $userId);
    $missing = [];
    $expired = [];
    $validDocs = [];

    foreach (REQUIRED_BIDDER_DOCS as $docType => $docLabel) {
        if (!isset($docs[$docType])) {
            $missing[$docType] = $docLabel;
            continue;
        }

        $info = get_document_validity_info($docs[$docType]['expiration_date'] ?? null);
        if ($info['is_expired']) {
            $expired[$docType] = [
                'label'           => $docLabel,
                'file_name'       => $docs[$docType]['file_name'],
                'expiration_date' => $docs[$docType]['expiration_date'],
                'date_formatted'  => $info['date_formatted'],
            ];
        } else {
            $validDocs[$docType] = $docs[$docType];
        }
    }

    $hasAllDocs = empty($missing);
    $hasExpired = !empty($expired);
    $isValid    = $hasAllDocs && !$hasExpired;

    $errorMessages = [];
    if (!$hasAllDocs) {
        $errorMessages[] = "Missing required document(s): " . implode(", ", $missing) . ".";
    }
    if ($hasExpired) {
        $expiredNames = array_map(fn($item) => $item['label'] . " (expired {$item['date_formatted']})", $expired);
        $errorMessages[] = "Expired document(s): " . implode(", ", $expiredNames) . ".";
    }

    return [
        'is_valid'       => $isValid,
        'has_all_docs'   => $hasAllDocs,
        'has_expired'    => $hasExpired,
        'missing'        => $missing,
        'expired'        => $expired,
        'valid_docs'     => $validDocs,
        'all_docs'       => $docs,
        'summary_error'  => !empty($errorMessages) ? implode(" ", $errorMessages) : null,
    ];
}

/**
 * Delete a physical file associated with a bidder document.
 * Handles both relative and absolute paths safely.
 *
 * @param string $filePath
 * @return bool
 */
function delete_bidder_physical_file(string $filePath): bool
{
    $filePath = trim($filePath);
    if (empty($filePath)) return false;

    // Resolve relative path to root directory
    $projectRoot = realpath(__DIR__ . '/..');
    $normalized  = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);

    // If path starts with ../ or uploads/, normalize
    $candidates = [
        $filePath,
        $projectRoot . DIRECTORY_SEPARATOR . ltrim($normalized, DIRECTORY_SEPARATOR . '.'),
        $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . ltrim(str_replace('uploads', '', $normalized), DIRECTORY_SEPARATOR . '.'),
    ];

    if (str_starts_with($filePath, '../')) {
        $candidates[] = realpath(__DIR__ . '/../' . $filePath) ?: '';
    }

    foreach ($candidates as $c) {
        if (!empty($c) && file_exists($c) && is_file($c)) {
            return @unlink($c);
        }
    }

    return false;
}

/**
 * Clean up all physical documents for a bidder directory
 *
 * @param int $userId
 * @return void
 */
function delete_bidder_all_files(int $userId): void
{
    $dir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'bidders' . DIRECTORY_SEPARATOR . $userId;
    if (is_dir($dir)) {
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                $fullPath = $dir . DIRECTORY_SEPARATOR . $file;
                if (is_file($fullPath)) {
                    @unlink($fullPath);
                }
            }
        }
        @rmdir($dir);
    }
}
