<?php
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set timezone
date_default_timezone_set('Asia/Tehran');

// Define paths
define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('PUBLIC_PATH', __DIR__);

// Load autoloader
require_once APP_PATH . '/Core/Autoloader.php';
\App\Core\Autoloader::register();

// Load helpers
require_once APP_PATH . '/Helpers/functions.php';
require_once APP_PATH . '/Helpers/constants.php';

// Set error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    logger()->error("PHP Error: $errstr", [
        'file' => $errfile,
        'line' => $errline,
        'errno' => $errno
    ]);
    
    if (APP_ENV === 'development') {
        echo "Error [$errno]: $errstr in $errfile on line $errline";
    }
});

// Set exception handler
set_exception_handler(function($exception) {
    logger()->critical("Uncaught Exception: " . $exception->getMessage(), [
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString()
    ]);
    
    if (APP_ENV === 'development') {
        echo "Exception: " . $exception->getMessage();
        echo "<pre>" . $exception->getTraceAsString() . "</pre>";
    } else {
        response()->error('An unexpected error occurred', 500);
    }
});

// Register routes
$router = new \App\Core\Router();

// Auth routes
$router->post('/api/auth/register', 'AuthController@register');
$router->post('/api/auth/login', 'AuthController@login');
$router->post('/api/auth/logout', 'AuthController@logout');
$router->get('/api/auth/me', 'AuthController@me');

// Workflow routes
$router->group(['prefix' => '/api/workflows'], function($router) {
    $router->get('', 'WorkflowController@index');
    $router->post('', 'WorkflowController@store');
    $router->get('/{id}', 'WorkflowController@show');
    $router->put('/{id}', 'WorkflowController@update');
    $router->delete('/{id}', 'WorkflowController@destroy');
    
    $router->get('/{id}/nodes', 'NodeController@index');
    $router->post('/{id}/nodes', 'NodeController@store');
    $router->get('/{id}/executions', 'ExecutionController@getExecutions');
});

// Node routes
$router->group(['prefix' => '/api/nodes'], function($router) {
    $router->put('/{id}', 'NodeController@update');
    $router->delete('/{id}', 'NodeController@destroy');
    $router->get('/types', 'NodeController@getNodeTypes');
});

// Connection routes
$router->post('/api/workflows/{id}/connections', 'NodeController@addConnection');
$router->delete('/api/connections/{id}', 'NodeController@removeConnection');

// Execution routes
$router->post('/api/workflows/{id}/run', 'ExecutionController@run');
$router->get('/api/executions/{id}', 'ExecutionController@show');
$router->get('/api/executions/{id}/logs', 'ExecutionController@logs');

// Webhook route
$router->post('/api/webhook/{public_id}', 'WebhookController@trigger');

// Dispatch the request
try {
    $router->dispatch();
} catch (\Exception $e) {
    logger()->error('Router dispatch error: ' . $e->getMessage());
    response()->error('Internal server error', 500);
}