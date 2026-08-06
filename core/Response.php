<?php

class Response
{
    public static function success($data = null, string $message = 'OK', int $httpCode = 200): void
    {
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
