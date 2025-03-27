<?php
namespace App\Helpers;
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;




class AuthHelper {
    const JWT_SECRET = 'your-256-bit-secret-key-here';
    const JWT_ALGORITHM = 'HS256';
    const JWT_EXPIRE = 3600; 
    public static function generateToken($userId) {
        $payload = [
            'iss' => 'your-domain.com',
            'aud' => 'your-domain.com',
            'iat' => time(),
            'exp' => time() + self::JWT_EXPIRE,
            'sub' => $userId
        ];
        return JWT::encode(
            $payload,
            self::JWT_SECRET,
            self::JWT_ALGORITHM
        );
    }
    public static function validateToken($token) {
        try {
            return JWT::decode(
                $token,
                new Key(self::JWT_SECRET, self::JWT_ALGORITHM)
            );
        } catch (\Firebase\JWT\ExpiredException $e) {
            error_log("JWT Expired: " . $e->getMessage());
            return false;
        } catch (\Firebase\JWT\SignatureInvalidException $e) {
            error_log("Invalid Signature: " . $e->getMessage());
            return false;
        } catch (\DomainException $e) {
            error_log("Domain Exception: " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            error_log("JWT Error: " . $e->getMessage());
            return false;
        }
    }
}