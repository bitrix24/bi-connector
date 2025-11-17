<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\HttpFoundation\Request;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Start processing installation request
$incomingRequest = Request::createFromGlobals();

Application::getLog()->debug('install.init', [
    'request' => $incomingRequest->request->all(),
    'query' => $incomingRequest->query->all(),
    'method' => $incomingRequest->getMethod(),
    'uri' => $incomingRequest->getRequestUri()
]);

try {
    $response = Application::processInstallation($incomingRequest);
    $response->send();
} catch (\Throwable $e) {
    Application::getLog()->error('install.error', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    $response = new \Symfony\Component\HttpFoundation\Response(
        'Installation failed: ' . $e->getMessage(),
        500
    );
    $response->send();
}
