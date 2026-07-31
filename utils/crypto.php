<?php

require_once __DIR__ . '/../bootstrap.php';

/**
 * Encrypt a file using AES-256-CBC.
 *
 * @param string $sourcePath       Path of the uploaded file.
 * @param string $destinationPath  Path where the encrypted file will be saved.
 * @param string $documentType     financial | eligibility
 *
 * @return array
 */
function encryptFile($sourcePath, $destinationPath, $documentType)
{
    /* ---------------------------------
       Validate document type
    --------------------------------- */

    if ($documentType !== 'financial' && $documentType !== 'eligibility') {
        return [
            'success' => false,
            'message' => 'Invalid document type.'
        ];
    }

    /* ---------------------------------
       Determine which key to use
    --------------------------------- */

    $keyName = ($documentType === 'financial')
        ? 'FINANCIAL_KEY'
        : 'ELIGIBILITY_KEY';

    $key = $_ENV[$keyName] ?? null;

    if (empty($key)) {
        return [
            'success' => false,
            'message' => "$keyName not found in .env."
        ];
    }

    /* ---------------------------------
       Encryption settings
    --------------------------------- */

    $cipher = 'AES-256-CBC';

    $ivLength = openssl_cipher_iv_length($cipher);

    $iv = random_bytes($ivLength);

    /* ---------------------------------
       Verify source file
    --------------------------------- */

    if (!file_exists($sourcePath)) {
        return [
            'success' => false,
            'message' => 'Source file does not exist.'
        ];
    }

    if (!is_readable($sourcePath)) {
        return [
            'success' => false,
            'message' => 'Source file is not readable.'
        ];
    }

    /* ---------------------------------
       Read file contents
    --------------------------------- */

    $data = file_get_contents($sourcePath);

    if ($data === false) {
        return [
            'success' => false,
            'message' => 'Failed to read source file.'
        ];
    }

    /* ---------------------------------
       Encrypt
    --------------------------------- */

    $encryptedData = openssl_encrypt(
        $data,
        $cipher,
        hex2bin($key),
        OPENSSL_RAW_DATA,
        $iv
    );

    if ($encryptedData === false) {
        return [
            'success' => false,
            'message' => 'Encryption failed.'
        ];
    }

    /* ---------------------------------
       Combine IV + Ciphertext
    --------------------------------- */

    $fileData = $iv . $encryptedData;

    /* ---------------------------------
       Save encrypted file
    --------------------------------- */

    if (file_put_contents($destinationPath, $fileData) === false) {
        return [
            'success' => false,
            'message' => 'Failed to save encrypted file.'
        ];
    }

    /* ---------------------------------
       Delete original file
    --------------------------------- */

    if (file_exists($sourcePath)) {

        if (!unlink($sourcePath)) {
            return [
                'success' => false,
                'message' => 'Encrypted successfully, but failed to delete original file.'
            ];
        }

    }

    return [
        'success' => true,
        'message' => 'File encrypted successfully.'
    ];
}

/**
 * Decrypt an encrypted file.
 *
 * @param string $sourcePath      Path to the encrypted (.enc) file.
 * @param string $documentType    financial | eligibility
 *
 * @return array
 */
function decryptFile($sourcePath, $documentType)
{
    /* ---------------------------------
       Validate document type
    --------------------------------- */

    if ($documentType !== 'financial' && $documentType !== 'eligibility') {
        return [
            'success' => false,
            'message' => 'Invalid document type.'
        ];
    }

    /* ---------------------------------
       Determine which key to use
    --------------------------------- */

    $keyName = ($documentType === 'financial')
        ? 'FINANCIAL_KEY'
        : 'ELIGIBILITY_KEY';

    $key = $_ENV[$keyName] ?? null;

    if (empty($key)) {
        return [
            'success' => false,
            'message' => "$keyName not found in .env."
        ];
    }

    /* ---------------------------------
       Encryption settings
    --------------------------------- */

    $cipher = 'AES-256-CBC';

    $ivLength = openssl_cipher_iv_length($cipher);

    /* ---------------------------------
       Verify encrypted file
    --------------------------------- */

    if (!file_exists($sourcePath)) {
        return [
            'success' => false,
            'message' => 'Encrypted file does not exist.'
        ];
    }

    if (!is_readable($sourcePath)) {
        return [
            'success' => false,
            'message' => 'Encrypted file is not readable.'
        ];
    }

    /* ---------------------------------
       Read encrypted file
    --------------------------------- */

    $fileData = file_get_contents($sourcePath);

    if ($fileData === false) {
        return [
            'success' => false,
            'message' => 'Failed to read encrypted file.'
        ];
    }

    /* ---------------------------------
       Extract IV
    --------------------------------- */

    $iv = substr($fileData, 0, $ivLength);

    /* ---------------------------------
       Extract ciphertext
    --------------------------------- */

    $encryptedData = substr($fileData, $ivLength);

    /* ---------------------------------
       Decrypt
    --------------------------------- */

    $decryptedData = openssl_decrypt(
        $encryptedData,
        $cipher,
        hex2bin($key),
        OPENSSL_RAW_DATA,
        $iv
    );

    if ($decryptedData === false) {
        return [
            'success' => false,
            'message' => 'Decryption failed.'
        ];
    }

    /* ---------------------------------
       Return plaintext
    --------------------------------- */

    return [
        'success' => true,
        'message' => 'File decrypted successfully.',
        'data' => $decryptedData
    ];
}


