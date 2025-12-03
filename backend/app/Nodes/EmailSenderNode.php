<?php
namespace App\Nodes;

class EmailSenderNode implements NodeInterface {
    private $config;

    public function __construct(array $config) {
        $this->config = array_merge([
            'to' => '',
            'subject' => '',
            'body' => '',
            'from' => '',
            'cc' => '',
            'bcc' => '',
            'is_html' => false
        ], $config);
    }

    public function execute(array $inputData): array {
        $config = $this->mergeInputData($inputData);

        $this->validate($config);

        $result = $this->sendEmail($config);

        return [
            'success' => $result,
            'data' => [
                'to' => $config['to'],
                'subject' => $config['subject'],
                'sent_at' => date('Y-m-d H:i:s')
            ]
        ];
    }

    private function sendEmail($config) {
        $headers = [];

        if (!empty($config['from'])) {
            $headers[] = "From: {$config['from']}";
        }

        if (!empty($config['cc'])) {
            $headers[] = "Cc: {$config['cc']}";
        }

        if (!empty($config['bcc'])) {
            $headers[] = "Bcc: {$config['bcc']}";
        }

        if ($config['is_html']) {
            $headers[] = "Content-Type: text/html; charset=UTF-8";
        } else {
            $headers[] = "Content-Type: text/plain; charset=UTF-8";
        }

        return mail(
            $config['to'],
            $config['subject'],
            $config['body'],
            implode("\r\n", $headers)
        );
    }

    public function getType(): string {
        return 'email_sender';
    }

    public function getName(): string {
        return $this->config['name'] ?? 'Email Sender';
    }

    public function validate(): bool {
        $config = $this->config;

        if (empty($config['to'])) {
            throw new \Exception('Recipient email is required');
        }

        if (!filter_var($config['to'], FILTER_VALIDATE_EMAIL)) {
            throw new \Exception('Invalid recipient email');
        }

        if (!empty($config['from']) && !filter_var($config['from'], FILTER_VALIDATE_EMAIL)) {
            throw new \Exception('Invalid sender email');
        }

        if (empty($config['subject'])) {
            throw new \Exception('Email subject is required');
        }

        if (empty($config['body'])) {
            throw new \Exception('Email body is required');
        }

        return true;
    }

    public function getOutputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'data' => [
                    'type' => 'object',
                    'properties' => [
                        'to' => ['type' => 'string'],
                        'subject' => ['type' => 'string'],
                        'sent_at' => ['type' => 'string']
                    ]
                ]
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

                    $fields = ['to', 'subject', 'body', 'from', 'cc', 'bcc'];
                    foreach ($fields as $field) {
                        if (isset($config[$field]) && is_string($config[$field])) {
                            $config[$field] = str_replace($placeholder, $value, $config[$field]);
                        }
                    }
                }
            }
        }

        return $config;
    }
}