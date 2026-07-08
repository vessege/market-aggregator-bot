<?php
declare(strict_types=1);

namespace MarketBot\Parsers;

use CurlHandle;

class HttpClient
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
     * Execute several requests in parallel (curl_multi). Used by live search
     * so that querying N marketplaces costs one round-trip, not N.
     *
     * @param array<string, array{method?:string, url:string, headers?:array<string,string>, body?:mixed}> $requests
     * @return array<string, array{ok:bool, status:int, body:string, error:?string}>
     */
    public function multiGet(array $requests): array
    {
        if ($requests === []) {
            return [];
        }
        $mh = curl_multi_init();
        $handles = [];
        foreach ($requests as $key => $req) {
            $ch = $this->buildHandle(
                strtoupper((string) ($req['method'] ?? 'GET')),
                $req['url'],
                $req['body'] ?? null,
                $req['headers'] ?? []
            );
            $handles[$key] = $ch;
            curl_multi_add_handle($mh, $ch);
        }

        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh, 1.0);
            }
        } while ($active && $status === CURLM_OK);

        $out = [];
        foreach ($handles as $key => $ch) {
            $body = curl_multi_getcontent($ch);
            $err = curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($body === null || ($body === '' && ($err !== '' || $httpCode === 0))) {
                // curl_error() can be empty for multi handles — synthesize one.
                $out[$key] = ['ok' => false, 'status' => $httpCode, 'body' => '', 'error' => $err !== '' ? $err : 'connection failed'];
            } else {
                $out[$key] = [
                    'ok'     => $httpCode >= 200 && $httpCode < 400,
                    'status' => $httpCode,
                    'body'   => (string) $body,
                    'error'  => null,
                ];
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    /** @param array<string,string> $headers */
    private function buildHandle(string $method, string $url, mixed $body, array $headers): CurlHandle
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $this->timeout));
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
        return $ch;
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
        $ch = $this->buildHandle($method, $url, $body, $headers);

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
