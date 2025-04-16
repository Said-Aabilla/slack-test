<?php

namespace App\Application\Middleware;

use Bjt\Log\Environment;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RateLimit\ApcuRateLimiter;
use RateLimit\Rate;

class APCULimiter implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (
            !class_exists(ApcuRateLimiter::class) ||
            !$request->getAttribute('user_id')
        ) {
            return $handler->handle($request);
        }

        $maxRequestByMinutes = 100;
        $maxRequestBySeconds = 10;

        $userId = $request->getAttribute('user_id');
        $teamId = $request->getAttribute('team_id');
        $uniqueKey = "$teamId-$userId";

        $rateLimiter = new ApcuRateLimiter('rate_limiter.');
        $requestByMinutesStatus = $rateLimiter->limitSilently($uniqueKey, Rate::perMinute($maxRequestByMinutes));
        $requestBySecondsStatus = $rateLimiter->limitSilently($uniqueKey, Rate::perSecond($maxRequestBySeconds));

        if ($requestBySecondsStatus->getRemainingAttempts() <= 0) {
            error_log('RATE_LIMIT 10/1 ' . $uniqueKey . ' ' . Environment::getCleanedPath());
        }

        if ($requestByMinutesStatus->getRemainingAttempts() <= 0) {
            error_log('RATE_LIMIT 100/60 ' . $uniqueKey . ' ' . Environment::getCleanedPath());
        }

        return $handler->handle($request);
    }
}
