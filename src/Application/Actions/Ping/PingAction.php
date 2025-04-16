<?php

namespace App\Application\Actions\Ping;

use App\Application\Actions\Action;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Stream;

class PingAction extends Action
{

    protected function action(): Response
    {
        $serverIp = explode('.', $_SERVER['SERVER_ADDR']);
        $serverIpLastPart = $serverIp ? end($serverIp) : '';
        $serverType = strpos($_SERVER['SERVER_ADDR'], "154.44.180.") !== false ? 'apiDev' : 'api';

        $payload = [
            'ping' => $serverType . $serverIpLastPart
        ];

        $body = new Stream(fopen('php://temp', 'r+'));
        $body->write(json_encode($payload));
        return $this->response->withBody($body);
    }
}