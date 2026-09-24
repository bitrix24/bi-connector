<?php

declare(strict_types=1);

namespace App;

use App\DataSource\ConnectionType;
use App\Validation\NameValidator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Start processing request
$request = Request::createFromGlobals();
$input = $request->request->all() ?: [];
$action = $request->query->get('action', '');
$connectionType = $request->query->get('connection_type', '');

// The request body carries connection credentials and never reaches the log
Application::getLog()->debug('index.init', [
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
        ConnectionType::tryFrom(is_string($connectionType) ? $connectionType : '') === null
    ) {
        throw new \InvalidArgumentException(sprintf(
            'Valid connection_type (%s) is required for action: %s',
            implode(', ', ConnectionType::values()),
            $action
        ));
    }

    // Table and field names come from outside and end up in SQL: the only place where they are validated
    $nameValidator = new NameValidator();

    if (in_array($action, ['table_description', 'data'])) {
        $nameValidator->validateTableName($input['table'] ?? '');
    }

    if ($action === 'data') {
        $nameValidator->validateFieldNames($input['select'] ?? []);

        $filter = $input['filter'] ?? [];

        if (!is_array($filter)) {
            throw new \InvalidArgumentException('Filter must be an array keyed by field names');
        }

        $nameValidator->validateFieldNames(array_keys($filter));
    }

    $connector = new BiConnector($input['connection'] ?? [], $connectionType, Application::getLog());

    switch ($action) {
        case 'check':
            Application::getLog()->info('BiConnector.check.start', ['connectionType' => $connectionType]);
            $response = $connector->check();
            break;

        case 'table_list':
            Application::getLog()->info('BiConnector.tableList.start', [
                'searchString' => $input['searchString'] ?? '',
                'connectionType' => $connectionType
            ]);
            $response = $connector->tableList($input['searchString'] ?? '');
            break;

        case 'table_description':
            Application::getLog()->info('BiConnector.tableDescription.start', [
                'table' => $input['table'] ?? '',
                'connectionType' => $connectionType
            ]);
            $response = $connector->tableDescription($input['table'] ?? '');
            break;

        case 'data':
            Application::getLog()->info('BiConnector.getData.start', [
                'table' => $input['table'] ?? '',
                'select' => $input['select'] ?? [],
                'filter' => $input['filter'] ?? [],
                'limit' => $input['limit'] ?? 100,
                'connectionType' => $connectionType
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
