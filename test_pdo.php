<?php
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '');
    echo "Connected locally with 127.0.0.1\n";
} catch (Throwable $e) {
    echo "127.0.0.1 Failed: " . $e->getMessage() . "\n";
}

try {
    $pdo2 = new PDO('mysql:host=localhost;port=3306', 'root', '');
    echo "Connected locally with localhost\n";
} catch (Throwable $e) {
    echo "localhost Failed: " . $e->getMessage() . "\n";
}
