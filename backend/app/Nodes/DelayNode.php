<?php
namespace App\Nodes;

class DelayNode implements NodeInterface {
    private $config;

    public function __construct(array $config) {
        $this->config = array_merge([
            'delay_type' => 'seconds',
            'value' => 5,
            'custom_date' => '',
            'random_min' => 1,
            'random_max' => 10
        ], $config);
    }

    public function execute(array $inputData): array {
        $delay = $this->calculateDelay();

        $this->applyDelay($delay);

        return [
            'success' => true,
            'data' => [
                'delay_applied' => $delay,
                'delay_type' => $this->config['delay_type'],
                'started_at' => date('Y-m-d H:i:s'),
                'finished_at' => date('Y-m-d H:i:s', time() + $delay)
            ]
        ];
    }

    private function calculateDelay() {
        $type = $this->config['delay_type'];

        switch ($type) {
            case 'seconds':
                return intval($this->config['value']);

            case 'minutes':
                return intval($this->config['value']) * 60;

            case 'hours':
                return intval($this->config['value']) * 3600;

            case 'until_date':
                if (empty($this->config['custom_date'])) {
                    throw new \Exception('Custom date is required for "until date" delay');
                }

                $targetTime = strtotime($this->config['custom_date']);
                $currentTime = time();

                if ($targetTime <= $currentTime) {
                    return 0;
                }

                return $targetTime - $currentTime;

            case 'random':
                $min = intval($this->config['random_min']);
                $max = intval($this->config['random_max']);

                if ($min > $max) {
                    throw new \Exception('Random min cannot be greater than max');
                }

                return rand($min, $max);

            default:
                throw new \Exception("Unknown delay type: {$type}");
        }
    }

    private function applyDelay($seconds) {
        if ($seconds > 0) {
            if ($seconds <= 30) {
                sleep($seconds);
            } else {
                $start = microtime(true);
                while (microtime(true) - $start < $seconds) {
                    usleep(100000);
                    if (memory_get_usage() > 100 * 1024 * 1024) {
                        throw new \Exception('Memory limit exceeded during delay');
                    }
                }
            }
        }
    }

    public function getType(): string {
        return 'delay';
    }

    public function getName(): string {
        return $this->config['name'] ?? 'Delay';
    }

    public function validate(): bool {
        $value = $this->config['value'] ?? 0;

        if ($this->config['delay_type'] === 'until_date') {
            if (empty($this->config['custom_date'])) {
                throw new \Exception('Custom date is required');
            }

            $timestamp = strtotime($this->config['custom_date']);
            if ($timestamp === false) {
                throw new \Exception('Invalid date format');
            }
        } else {
            if (!is_numeric($value) || $value < 0) {
                throw new \Exception('Delay value must be a positive number');
            }

            if ($value > 86400) {
                throw new \Exception('Delay cannot exceed 24 hours');
            }
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
                        'delay_applied' => ['type' => 'integer'],
                        'delay_type' => ['type' => 'string'],
                        'started_at' => ['type' => 'string'],
                        'finished_at' => ['type' => 'string']
                    ]
                ]
            ]
        ];
    }

    public function getConfig(): array {
        return $this->config;
    }
}