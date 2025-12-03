<?php
namespace App\Nodes;

use Exception;

class FunctionNode implements NodeInterface {
    private $config;
    private $allowedFunctions;

    public function __construct(array $config) {
        $this->config = array_merge([
            'code' => '',
            'input_mapping' => [],
            'output_mapping' => []
        ], $config);

        $this->allowedFunctions = require CONFIG_PATH . '/settings.php'['allowedFunctions'] ?? [];
    }

    public function execute(array $inputData): array {
        $code = $this->config['code'];
        $inputMapping = $this->config['input_mapping'] ?? [];

        $sandbox = $this->createSandbox();

        foreach ($inputMapping as $varName => $inputKey) {
            $sandbox[$varName] = $inputData[$inputKey] ?? null;
        }

        $result = $this->executeInSandbox($code, $sandbox);

        return [
            'success' => true,
            'data' => $result,
            'output' => $this->mapOutput($result, $this->config['output_mapping'] ?? [])
        ];
    }

    private function createSandbox() {
        $sandbox = [];

        foreach ($this->allowedFunctions as $funcName) {
            if (function_exists($funcName)) {
                $sandbox[$funcName] = function(...$args) use ($funcName) {
                    return call_user_func_array($funcName, $args);
                };
            }
        }

        $sandbox['json_encode'] = function($value) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        };

        $sandbox['json_decode'] = function($value, $assoc = true) {
            return json_decode($value, $assoc);
        };

        return $sandbox;
    }

    private function executeInSandbox($code, $sandbox) {
        $functionCode = "return function(\$input) {\n" . $code . "\n};";

        try {
            $func = eval($functionCode);

            if (!is_callable($func)) {
                throw new Exception('Invalid function code');
            }

            extract($sandbox);
            $result = $func($sandbox);

            return $result;

        } catch (Exception $e) {
            throw new Exception('Code execution error: ' . $e->getMessage());
        }
    }

    public function getType(): string {
        return 'function';
    }

    public function getName(): string {
        return $this->config['name'] ?? 'Code Function';
    }

    public function validate(): bool {
        if (empty(trim($this->config['code']))) {
            throw new Exception('Code is required for function node');
        }

        $dangerousPatterns = [
            '/system\s*\(/i',
            '/exec\s*\(/i',
            '/shell_exec\s*\(/i',
            '/eval\s*\(/i',
            '/`.*`/',
            '/passthru\s*\(/i',
            '/proc_open\s*\(/i',
            '/popen\s*\(/i',
            '/include\s*\(/i',
            '/require\s*\(/i'
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $this->config['code'])) {
                throw new Exception('Potentially dangerous code detected');
            }
        }

        return true;
    }

    public function getOutputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'data' => ['type' => 'mixed'],
                'output' => ['type' => 'object']
            ]
        ];
    }

    public function getConfig(): array {
        return $this->config;
    }

    private function mapOutput($result, $outputMapping) {
        $output = [];

        if (is_array($result)) {
            foreach ($outputMapping as $outputKey => $resultKey) {
                $output[$outputKey] = $result[$resultKey] ?? null;
            }
        }

        return $output;
    }
}