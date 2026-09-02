<?php
// data.php
class Database {
    private $host = "localhost";
    private $db_name = "bhadra_foods"; // Replace with your MySQL DB name
    private $username = "root";        // Replace with your MySQL username
    private $password = "";            // Replace with your MySQL password
    public $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8",
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch(PDOException $exception) {
            // Return null or throw so register.php can report the exact issue
            return null;
        }
        return $this->conn;
    }
}
?>