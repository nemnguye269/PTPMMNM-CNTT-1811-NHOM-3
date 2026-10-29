<?php
declare(strict_types=1);
namespace Gtfs;

use PDO;

/** Đăng ký, đăng nhập và tra cứu tài khoản người dùng. */
final class Auth
{
    public function __construct(private PDO $pdo)
    {
    }

    public function register(string $username, string $email, string $password): int
    {
        $username = trim($username);
        $email = trim(mb_strtolower($email));

        if (mb_strlen($username) < 3 || mb_strlen($username) > 50) {
            throw new \InvalidArgumentException('Tên đăng nhập phải từ 3 đến 50 ký tự.');
        }
        if (!preg_match('/^[\p{L}\p{N}_.-]+$/u', $username)) {
            throw new \InvalidArgumentException('Tên đăng nhập chỉ được chứa chữ, số, dấu chấm, gạch dưới hoặc gạch ngang.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Email không hợp lệ.');
        }
        if (strlen($password) < 6) {
            throw new \InvalidArgumentException('Mật khẩu phải có ít nhất 6 ký tự.');
        }

        $check = $this->pdo->prepare(
            'SELECT id FROM users
             WHERE lower(username) = lower(:username)
                OR lower(email) = lower(:email)
             LIMIT 1'
        );
        $check->execute([':username' => $username, ':email' => $email]);
        if ($check->fetch()) {
            throw new \InvalidArgumentException('Tên đăng nhập hoặc email đã tồn tại.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, email, password_hash, role, created_at)
             VALUES (:username, :email, :password_hash, :role, :created_at)'
        );
        $stmt->execute([
            ':username' => $username,
            ':email' => $email,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':role' => 'user',
            ':created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function login(string $identity, string $password): ?array
    {
        $identity = trim($identity);
        if ($identity === '' || $password === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM users
             WHERE lower(username) = lower(:identity)
                OR lower(email) = lower(:identity)
             LIMIT 1'
        );
        $stmt->execute([':identity' => $identity]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            return null;
        }

        return $user;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, username, email, role, created_at
             FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }
}
