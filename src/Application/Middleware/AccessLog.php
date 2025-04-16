<?php

namespace App\Application\Middleware;

use App\Application\Logger\IntegrationLoggerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AccessLog implements MiddlewareInterface
{
    private IntegrationLoggerInterface $logger;

    public function __construct(IntegrationLoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->logger->info($request->getMethod() . ' ' . $request->getUri()->getPath());
        $response = $handler->handle($request);
        $this->logger->info($response->getStatusCode() . ' ' . $response->getReasonPhrase());
        return $response;
    }
}
