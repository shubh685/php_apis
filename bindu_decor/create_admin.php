<?php

require_once "database.php";

$name = "Bindu Decor Admin";
$email = "admin@bindudecor.com";
$password = "Admin@123456";

$hashedPassword = password_hash(
    $password,
    PASSWORD_DEFAULT
);

$stmt = $pdo->prepare("
    INSERT INTO users
    (name, email, password)
    VALUES
    (?, ?, ?)
");

$stmt->execute([
    $name,
    $email,
    $hashedPassword
]);

echo "Admin created successfully.";

?>