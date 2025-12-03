<?php
namespace App\Services;

use App\Core\Database;
use App\Services\ExecutionService;
use Exception;

class WebhookService {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function triggerWebhook($publicId, $requestData) {
        $workflow = $this->db->select(
            "SELECT w.*, wh.secret_token, wh.max_calls_per_hour 
             FROM workflows w
             LEFT JOIN webhooks wh ON w.id = wh.workflow_id
             WHERE w.public_id = ? AND w.is_active = 1 
             AND w.trigger_type = 'webhook'",
            [$publicId]
        );

        if (empty($workflow)) {
            throw new Exception('Webhook not found or inactive');
        }

        $workflow = $workflow[0];

        if (!$this->checkRateLimit($workflow['id'])) {
            throw new Exception('Rate limit exceeded');
        }

        if (!empty($workflow['secret_token'])) {
            $this->validateSignature($workflow['secret_token'], $requestData);
        }

        $webhookLogId = $this->logWebhookRequest($workflow['id'], $requestData);

        try {
            $executionService = new ExecutionService();
            $result = $executionService->executeWorkflow(
                $workflow['id'],
                $requestData,
                $workflow['user_id'],
                'webhook'
            );

            $this->updateWebhookLog($webhookLogId, 200, $result);

            $this->db->update(
                "UPDATE webhooks SET last_called = NOW() WHERE workflow_id = ?",
                [$workflow['id']]
            );

            return $result;

        } catch (Exception $e) {
            $this->updateWebhookLog($webhookLogId, 500, ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function checkRateLimit($workflowId) {
        $hourAgo = date('Y-m-d H:i:s', strtotime('-1 hour'));

        $count = $this->db->select(
            "SELECT COUNT(*) as count FROM webhook_logs 
             WHERE webhook_id IN (
                 SELECT id FROM webhooks WHERE workflow_id = ?
             ) AND created_at > ?",
            [$workflowId, $hourAgo]
        );

        $maxCalls = $this->db->select(
            "SELECT max_calls_per_hour FROM webhooks WHERE workflow_id = ?",
            [$workflowId]
        );

        $maxCalls = $maxCalls[0]['max_calls_per_hour'] ?? 100;

        return ($count[0]['count'] ?? 0) < $maxCalls;
    }

    private function validateSignature($secretToken, $requestData) {
        $signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
        $payload = file_get_contents('php://input');

        $expectedSignature = hash_hmac('sha256', $payload, $secretToken);

        if (!hash_equals($expectedSignature, $signature)) {
            throw new Exception('Invalid webhook signature');
        }
    }

    private function logWebhookRequest($workflowId, $requestData) {
        $webhook = $this->db->select(
            "SELECT id FROM webhooks WHERE workflow_id = ?",
            [$workflowId]
        );

        $webhookId = $webhook[0]['id'] ?? null;

        if (!$webhookId) {
            $webhookKey = 'wh_' . bin2hex(random_bytes(16));
            $webhookId = $this->db->insert(
                "INSERT INTO webhooks (workflow_id, webhook_key) VALUES (?, ?)",
                [$workflowId, $webhookKey]
            );
        }

        return $this->db->insert(
            "INSERT INTO webhook_logs 
             (webhook_id, ip_address, user_agent, request_method, 
              request_headers, request_body) 
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $webhookId,
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                $_SERVER['REQUEST_METHOD'] ?? 'POST',
                json_encode(getallheaders()),
                json_encode($requestData)
            ]
        );
    }

    private function updateWebhookLog($logId, $responseCode, $responseData) {
        $this->db->update(
            "UPDATE webhook_logs 
             SET response_code = ?, response_body = ? 
             WHERE id = ?",
            [$responseCode, json_encode($responseData), $logId]
        );
    }
}