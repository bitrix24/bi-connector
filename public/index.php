<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Start processing request
$request = Request::createFromGlobals();
$input = $request->request->all() ?: [];
$action = $request->query->get('action', '');
$connectionType = $request->query->get('connection_type', '');

Application::getLog()->debug('index.init', [
    'request' => $request->request->all(),
    'query' => $request->query->all(),
    'input' => $input,
    'action' => $action,
    'connectionType' => $connectionType,
    'method' => $request->getMethod(),
    'uri' => $request->getRequestUri()
]);

$response = null;

try {
    // Validate connection parameters for data requests
    if (
        in_array($action, ['check', 'table_list', 'table_description', 'data']) &&
        (empty($input['connection']) || !is_array($input['connection']))
    ) {
        throw new \InvalidArgumentException('Connection parameters are required for action: ' . $action);
    }

    // Validate connection type
    if (
        in_array($action, ['check', 'table_list', 'table_description', 'data']) &&
        !in_array($connectionType, ['mysql', 'postgresql'])
    ) {
        throw new \InvalidArgumentException(
            'Valid connection_type (mysql or postgresql) is required for action: ' . $action
        );
    }

    $connector = new BiConnector($input['connection'] ?? [], $connectionType, Application::getLog());

    switch ($action) {
        case 'check':
            Application::getLog()->info('BiConnector.check.start', ['connection' => $input['connection'] ?? []]);
            $response = $connector->check();
            break;

        case 'table_list':
            Application::getLog()->info('BiConnector.tableList.start', [
                'searchString' => $input['searchString'] ?? '',
                'connection' => $input['connection'] ?? []
            ]);
            $response = $connector->tableList($input['searchString'] ?? '');
            break;

        case 'table_description':
            Application::getLog()->info('BiConnector.tableDescription.start', [
                'table' => $input['table'] ?? '',
                'connection' => $input['connection'] ?? []
            ]);
            $response = $connector->tableDescription($input['table'] ?? '');
            break;

        case 'data':
            Application::getLog()->info('BiConnector.getData.start', [
                'table' => $input['table'] ?? '',
                'select' => $input['select'] ?? [],
                'filter' => $input['filter'] ?? [],
                'limit' => $input['limit'] ?? 100,
                'connection' => $input['connection'] ?? []
            ]);
            $response = $connector->getData(
                $input['table'] ?? '',
                $input['select'] ?? [],
                $input['filter'] ?? [],
                intval($input['limit']) === 0 ? 100 : intval($input['limit'])
            );
            break;

        default:
            Application::getLog()->warning('index.unknownAction', ['action' => $action]);
            $response = new Response(
                json_encode(['error' => 'Unknown action: ' . $action]),
                400,
                ['Content-Type' => 'application/json']
            );
            break;
    }
} catch (\Throwable $e) {
    Application::getLog()->error('index.error', [
        'action' => $action,
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    $response = new Response(
        json_encode(['error' => $e->getMessage()]),
        500,
        ['Content-Type' => 'application/json']
    );
}

Application::getLog()->debug('index.response', [
    'statusCode' => $response->getStatusCode(),
    'contentType' => $response->headers->get('Content-Type')
]);

$response->send();
