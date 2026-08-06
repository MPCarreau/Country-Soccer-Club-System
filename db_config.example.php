<?php

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404);
    exit;
}

return [
    'host' => 'wdc353.encs.concordia.ca',
    'port' => '3306',
    'name' => 'wdc353_1',
    'user' => 'wdc353_1',
    'pass' => 'DATABASE_PASSWORD_HERE',
];
