<?php

require_once 'utils/crypto.php';

$result = encryptFile(
    'dummy.pdf',
    'dummy.enc',
    'financial'
);

print_r($result);