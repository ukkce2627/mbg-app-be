<?php
/**
 * Wrapper publish notifikasi email lewat Amazon SNS.
 * Sama seperti S3Uploader, kredensial AWS otomatis lewat mekanisme server.
 * Kegagalan publish TIDAK BOLEH menggagalkan proses utama — selalu panggil
 * lewat try-catch di pemanggil, dan class ini sendiri tidak pernah throw.
 */
class SnsNotifier
{
    private ?string $topicArn;

    public function __construct()
    {
        $full = require __DIR__ . '/../config.php';
        $this->topicArn = $full['sns']['topic_arn'] ?? null;
    }

    /**
     * @param string $eventType  'laporan' | 'aduan'
     * @param array  $context    ['sppg_nama' => ..., 'kategori' => ... (opsional)]
     */
    public function publish(string $eventType, array $context): void
    {
        try {
            $message = sprintf(
                'Event baru: %s | SPPG: %s | Kategori: %s | Waktu: %s',
                $eventType,
                $context['sppg_nama'] ?? '-',
                $context['kategori'] ?? '-',
                date('Y-m-d H:i:s')
            );

            if (class_exists('\Aws\Sns\SnsClient') && $this->topicArn) {
                $client = new \Aws\Sns\SnsClient(['version' => 'latest', 'region' => (require __DIR__ . '/../config.php')['s3']['region']]);
                $client->publish([
                    'TopicArn' => $this->topicArn,
                    'Message'  => $message,
                    'Subject'  => 'Notifikasi MBG: ' . ucfirst($eventType) . ' Baru',
                ]);
            } else {
                error_log('[SNS fallback/dev] ' . $message);
            }
        } catch (\Throwable $e) {
            // Wajib: kegagalan SNS tidak boleh menggagalkan proses utama.
            error_log('SNS publish gagal: ' . $e->getMessage());
        }
    }
}
