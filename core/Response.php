<?php

class Response
{
    /**
     * Tulis & tutup session SEBELUM body dikirim ke FE. Tanpa ini data login
     * baru ditulis saat request selesai -- bisa sesudah FE menerima response
     * dan sudah meminta halaman berikutnya (race -> "session tidak valid").
     */
    private static function flushSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public static function success($data = null, string $message = 'OK', int $httpCode = 200): void
    {
        self::flushSession();
        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'data'    => $data,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error(string $error, string $code = 'ERROR', int $httpCode = 400): void
    {
        self::flushSession();
        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error'   => $error,
            'code'    => $code,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
