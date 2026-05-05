<?php
declare(strict_types=1);

namespace MarketBot\Parsers;

final class HttpClient
{
    public function __construct(
        private string $userAgent = 'Mozilla/5.0',
        private int $timeout = 20,
        private int $delayMs = 0,
    ) {}

    /**
     * @param array<string,string> $headers
     * @return array{ok:bool, status:int, body:string, error:?string}
     */
    public function get(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, null, $headers);
    }

    /**
     * @param array<string,string> $headers
     * @return array{ok:bool, status:int, body:string, error:?string}
     */
    public function post(string $url, mixed $body, array $headers = []): array
    {
        return $this->request('POST', $url, $body, $headers);
    }

    /**
     * @param array<string,string> $headers
     * @return array{ok:bool, status:int, body:string, error:?string}
     */
    private function request(string $method, string $url, mixed $body, array $headers): array
    {
        if ($this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $hdr = [];
        foreach ($headers as $k => $v) {
            $hdr[] = $k . ': ' . $v;
        }
        if (!empty($hdr)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
        }
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
            }
        }

        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $err ?: 'curl error'];
        }

        return ['ok' => $status >= 200 && $status < 400, 'status' => $status, 'body' => (string) $resp, 'error' => null];
    }
}
