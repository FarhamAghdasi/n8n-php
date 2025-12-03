<?php
namespace App\Nodes;

class MysqlQueryNode implements NodeInterface {
    private $config;

    public function __construct(array $config) {
        $this->config = array_merge([
            'query' => '',
            'host' => 'localhost',
            'database' => '',
            'username' => '',
            'password' => '',
            'port' => 3306,
            'charset' => 'utf8mb4'
        ], $config);
    }

    public function execute(array $inputData): array {
        $config = $this->mergeInputData($inputData);

        $this->validate($config);

        $result = $this->executeQuery($config);

        return [
            'success' => true,
            'data' => $result,
            'row_count' => count($result),
            'executed_at' => date('Y-m-d H:i:s')
        ];
    }

    private function executeQuery($config) {
        $connection = new \mysqli(
            $config['host'],
            $config['username'],
            $config['password'],
            $config['database'],
            $config['port']
        );

        if ($connection->connect_error) {
            throw new \Exception('Database connection failed: ' . $connection->connect_error);
        }

        $connection->set_charset($config['charset']);

        $query = $this->prepareQuery($config['query'], $config['params'] ?? []);

        $result = $connection->query($query);

        if (!$result) {
            $error = $connection->error;
            $connection->close();
            throw new \Exception('Query execution failed: ' . $error);
        }

        $rows = [];

        if ($result === true) {
            $rows = [
                'affected_rows' => $connection->affected_rows,
                'insert_id' => $connection->insert_id
            ];
        } else {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }

        $connection->close();

        return $rows;
    }

    private function prepareQuery($query, $params) {
        foreach ($params as $key => $value) {
            $placeholder = ':' . $key;
            if (strpos($query, $placeholder) !== false) {
                $escapedValue = $this->escapeSqlValue($value);
                $query = str_replace($placeholder, $escapedValue, $query);
            }
        }

        return $query;
    }

    private function escapeSqlValue($value) {
        if (is_null($value)) {
            return 'NULL';
        }

        if (is_numeric($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        $tempConnection = new \mysqli($this->config['host'], '', '');
        $escaped = $tempConnection->real_escape_string($value);
        $tempConnection->close();

        return "'" . $escaped . "'";
    }

    public function getType(): string {
        return 'mysql_query';
    }

    public function getName(): string {
        return $this->config['name'] ?? 'MySQL Query';
    }

    public function validate(): bool {
        $config = $this->config;

        if (empty($config['query'])) {
            throw new \Exception('SQL query is required');
        }

        if (empty($config['database'])) {
            throw new \Exception('Database name is required');
        }

        $dangerousKeywords = ['DROP', 'TRUNCATE', 'DELETE FROM', 'UPDATE.*SET', 'INSERT INTO'];
        foreach ($dangerousKeywords as $keyword) {
            if (preg_match("/$keyword/i", $config['query'])) {
                throw new \Exception('Potentially dangerous query detected');
            }
        }

        return true;
    }

    public function getOutputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'data' => ['type' => 'array'],
                'row_count' => ['type' => 'integer'],
                'executed_at' => ['type' => 'string']
            ]
        ];
    }

    public function getConfig(): array {
        return $this->config;
    }

    private function mergeInputData($inputData) {
        $config = $this->config;

        if (isset($inputData['params']) && is_array($inputData['params'])) {
            $config['params'] = $inputData['params'];
        }

        if (isset($inputData['data']) && is_array($inputData['data'])) {
            foreach ($inputData['data'] as $key => $value) {
                if (is_string($value)) {
                    $placeholder = '{{' . $key . '}}';
                    $config['query'] = str_replace($placeholder, $value, $config['query']);
                }
            }
        }

        return $config;
    }
}