<?php
namespace App\Nodes;

class HttpNode implements NodeInterface {
    private $config;
    private $lastResponse;

    public function __construct(array $config) {
        $this->config = array_merge([
            'method' => 'GET',
            'url' => '',
            'headers' => [],
            'body' => null,
            'timeout' => 30,
            'follow_redirects' => true,
            'verify_ssl' => true,
            'retry_count' => 0,
            'retry_delay' => 1
        ], $config);
    }

    public function execute(array $inputData): array {
        $config = $this->mergeInputData($inputData);

        $this->validate($config);

        $response = $this->makeHttpRequestWithRetry($config);

        $this->lastResponse = $response;

        return [
            'success' => $response['success'],
            'data' => $response['data'],
            'status_code' => $response['status_code'],
            'headers' => $response['headers'],
            'execution_time' => $response['execution_time']
        ];
    }

    private function makeHttpRequestWithRetry($config) {
        $retryCount = $config['retry_count'] ?? 0;
        $retryDelay = $config['retry_delay'] ?? 1;

        for ($attempt = 0; $attempt <= $retryCount; $attempt++) {
            if ($attempt > 0) {
                sleep($retryDelay);
            }

            $response = $this->makeHttpRequest($config);

            if ($response['success'] || $attempt === $retryCount) {
                return $response;
            }
        }

        return $response;
    }

    private function makeHttpRequest($config) {
        $startTime = microtime(true);

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $config['url']);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($config['method']));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $config['timeout']);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $config['follow_redirects']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $config['verify_ssl']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $config['verify_ssl'] ? 2 : 0);

        if (!empty($config['headers'])) {
            $headers = [];
            foreach ($config['headers'] as $key => $value) {
                $headers[] = "$key: $value";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        if (in_array($config['method'], ['POST', 'PUT', 'PATCH']) && $config['body']) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $config['body']);
        }

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        if ($ch) {
            curl_close($ch);
        }

        if ($error) {
            return [
                'success' => false,
                'error' => $error,
                'status_code' => $statusCode,
                'headers' => [],
                'execution_time' => $executionTime
            ];
        }

        $decodedResponse = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $responseData = $decodedResponse;
        } else {
            $responseData = $response;
        }

        return [
            'success' => $statusCode >= 200 && $statusCode < 300,
            'data' => $responseData,
            'status_code' => $statusCode,
            'headers' => [],
            'execution_time' => $executionTime
        ];
    }

    public function getType(): string {
        return 'http_request';
    }

    public function getName(): string {
        return $this->config['name'] ?? 'HTTP Request';
    }

    public function validate(): bool {
        return $this->validateConfig($this->config);
    }

    private function validateConfig($config) {
        if (empty($config['url'])) {
            throw new \Exception('URL is required for HTTP node');
        }

        $validMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD'];
        if (!in_array(strtoupper($config['method']), $validMethods)) {
            throw new \Exception('Invalid HTTP method');
        }

        if (!filter_var($config['url'], FILTER_VALIDATE_URL)) {
            throw new \Exception('Invalid URL format');
        }

        if ($config['timeout'] < 1 || $config['timeout'] > 300) {
            throw new \Exception('Timeout must be between 1 and 300 seconds');
        }

        return true;
    }

    public function getOutputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'data' => ['type' => 'mixed'],
                'status_code' => ['type' => 'integer'],
                'execution_time' => ['type' => 'number']
            ]
        ];
    }

    public function getConfig(): array {
        return $this->config;
    }

    private function mergeInputData($inputData) {
        $config = $this->config;

        if (isset($inputData['data']) && is_array($inputData['data'])) {
            foreach ($inputData['data'] as $key => $value) {
                if (is_string($value)) {
                    $placeholder = '{{' . $key . '}}';

                    if (isset($config['url'])) {
                        $config['url'] = str_replace($placeholder, urlencode($value), $config['url']);
                    }

                    if (isset($config['body']) && is_string($config['body'])) {
                        $config['body'] = str_replace($placeholder, $value, $config['body']);
                    }
                }
            }
        }

        return $config;
    }
}