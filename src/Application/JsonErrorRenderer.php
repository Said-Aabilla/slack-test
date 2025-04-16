<?php

namespace App\Application;

use App\Application\Exceptions\CustomIntegrationException;
use Slim\Exception\HttpSpecializedException;
use Slim\Interfaces\ErrorRendererInterface;
use Throwable;

class JsonErrorRenderer implements ErrorRendererInterface
{
    public function __invoke(Throwable $exception, bool $displayErrorDetails): string
    {
        if ($exception instanceof HttpSpecializedException) {
            $errorPayload = $this->getHttpSpecializedExceptionResponse($exception, $displayErrorDetails);
        } else {
            $errorPayload = $this->getDefaultExceptionResponse($exception, $displayErrorDetails);
        }

        return json_encode($errorPayload);
    }

    private function getHttpSpecializedExceptionResponse(
        HttpSpecializedException $exception,
        bool $displayErrorDetails
    ): array {
        $errorPayload = [
            'http_status_code' => $exception->getCode(),
            'error' => [
                'type' => $exception->getTitle(),
                'description' => $exception->getMessage()
            ]
        ];

        if ($displayErrorDetails) {
            $errorPayload['error']['trace'] = $exception->getTrace();
        }

        return $errorPayload;
    }

    private function getDefaultExceptionResponse(Throwable $exception, bool $displayErrorDetails): array
    {
        $errorPayload = [
            'code' => $exception->getCode()
        ];

        if ($displayErrorDetails) {
            $errorPayload['error']['description'] = $exception->getMessage();
            $errorPayload['error']['trace'] = $exception->getTrace();
        }

        return $errorPayload;
    }
}
