<?php
namespace App\Nodes;

use App\Nodes\HttpNode;
use App\Nodes\WebhookTriggerNode;
use App\Nodes\EmailSenderNode;
use App\Nodes\MysqlQueryNode;
use App\Nodes\DelayNode;
use App\Nodes\FunctionNode;

class NodeFactory {
    
    public static function create($type, $config) {
        switch ($type) {
            case 'http_request':
                return new HttpNode($config);
                
            case 'webhook_trigger':
                return new WebhookTriggerNode($config);
                
            case 'email_sender':
                return new EmailSenderNode($config);
                
            case 'mysql_query':
                return new MysqlQueryNode($config);
                
            case 'delay':
                return new DelayNode($config);
                
            case 'function':
                return new FunctionNode($config);
                
            default:
                throw new \Exception("Unknown node type: {$type}");
        }
    }
    
    public static function getNodeTypes() {
        return [
            'http_request' => [
                'name' => 'HTTP Request',
                'description' => 'Make HTTP requests to APIs',
                'icon' => '🌐',
                'category' => 'Integration',
                'inputs' => 1,
                'outputs' => 1
            ],
            'webhook_trigger' => [
                'name' => 'Webhook Trigger',
                'description' => 'Trigger workflow via webhook',
                'icon' => '🔗',
                'category' => 'Trigger',
                'inputs' => 0,
                'outputs' => 1
            ],
            'email_sender' => [
                'name' => 'Email Sender',
                'description' => 'Send email notifications',
                'icon' => '📧',
                'category' => 'Communication',
                'inputs' => 1,
                'outputs' => 1
            ],
            'mysql_query' => [
                'name' => 'MySQL Query',
                'description' => 'Execute MySQL queries',
                'icon' => '🗄️',
                'category' => 'Database',
                'inputs' => 1,
                'outputs' => 1
            ],
            'delay' => [
                'name' => 'Delay',
                'description' => 'Add delay to workflow',
                'icon' => '⏱️',
                'category' => 'Utility',
                'inputs' => 1,
                'outputs' => 1
            ],
            'function' => [
                'name' => 'Function',
                'description' => 'Execute custom PHP code',
                'icon' => '⚙️',
                'category' => 'Code',
                'inputs' => 1,
                'outputs' => 1
            ]
        ];
    }
    
    public static function getDefaultConfig($type) {
        $defaults = [
            'http_request' => [
                'method' => 'GET',
                'url' => '',
                'headers' => [],
                'body' => null,
                'timeout' => 30,
                'follow_redirects' => true,
                'verify_ssl' => true
            ],
            'email_sender' => [
                'to' => '',
                'subject' => '',
                'body' => '',
                'from' => '',
                'cc' => '',
                'bcc' => ''
            ],
            'mysql_query' => [
                'query' => '',
                'host' => 'localhost',
                'database' => '',
                'username' => '',
                'password' => ''
            ],
            'delay' => [
                'delay_type' => 'seconds',
                'value' => 5
            ],
            'function' => [
                'code' => '// Your PHP code here',
                'input_mapping' => [],
                'output_mapping' => []
            ]
        ];
        
        return $defaults[$type] ?? [];
    }
}