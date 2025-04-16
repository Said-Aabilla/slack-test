<?php

namespace App\Application\Actions\User;

use App\Application\Actions\Action;
use App\Domain\User\Service\RecoverPassword;
use Psr\Http\Message\ResponseInterface as Response;
use App\Application\Logger\IntegrationLoggerInterface;
use Slim\Psr7\Stream;

class SendRecoverPasswordEmailAction extends Action
{

    /**
     * @var \App\Domain\User\Service\RecoverPassword
     */
    private RecoverPassword $recoverPassword;

    public function __construct(IntegrationLoggerInterface $logger, RecoverPassword $recoverPassword)
    {
        parent::__construct($logger);
        $this->recoverPassword = $recoverPassword;
    }

    protected function action(): Response
    {
        $userEmail = $this->request->getParsedBody()['email'] ?? null;

        if (empty($userEmail)) {
            $payload = [
                'status' => 0,
                'errors' => ['Missing parameter "email"'],
            ];

            $body = new Stream(fopen('php://temp', 'r+'));
            $body->write(json_encode($payload));
            return $this->response->withBody($body)->withStatus(400);
        }

        $this->recoverPassword->sendPasswordRecoveryEmail($userEmail);

        $payload = [
            'status' => 1
        ];
        $body = new Stream(fopen('php://temp', 'r+'));
        $body->write(json_encode($payload));
        return $this->response->withBody($body);
    }
}