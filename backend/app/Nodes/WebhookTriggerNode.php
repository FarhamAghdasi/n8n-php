<?php
namespace App\Nodes;

class WebhookTriggerNode implements NodeInterface {
    private $config;

    public function __construct(array $config) {
        $this->config = array_merge([
            'name' => 'Webhook Trigger',
            'webhook_path' => '',
            'method' => 'POST',
            'response_code' => 200,
            'response_body' => '{"status": "ok"}'
        ], $config);
    }

    public function execute(array $inputData): array {
        return [
            'success' => true,
            'data' => $inputData,
            'webhook_received' => true,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    public function getType(): string {
        return 'webhook_trigger';
    }

    public function getName(): string {
        return $this->config['name'] ?? 'Webhook Trigger';
    }

    public function validate(): bool {
        if (empty($this->config['webhook_path'])) {
            throw new \Exception('Webhook path is required');
        }

        $validMethods = ['GET', 'POST', 'PUT', 'DELETE'];
        if (!in_array(strtoupper($this->config['method']), $validMethods)) {
            throw new \Exception('Invalid HTTP method for webhook');
        }

        return true;
    }

    public function getOutputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'data' => ['type' => 'mixed'],
                'webhook_received' => ['type' => 'boolean'],
                'timestamp' => ['type' => 'string']
            ]
        ];
    }

    public function getConfig(): array {
        return $this->config;
    }
}