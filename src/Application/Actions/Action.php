<?php

declare(strict_types=1);

namespace App\Application\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Application\Logger\IntegrationLoggerInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpSpecializedException;

abstract class Action
{
    /**
     * @var IntegrationLoggerInterface
     */
    protected IntegrationLoggerInterface $logger;

    /**
     * @var Request
     */
    protected Request $request;

    /**
     * @var Response
     */
    protected Response $response;

    /**
     * @var array
     */
    protected array $args;

    /**
     * @var array
     */
    protected array $settings = [];

    /**
     * @paramIntegrationLoggerInterface$logger
     */
    public function __construct(IntegrationLoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param Request $request
     * @param Response $response
     * @param array $args
     * @return Response
     * @throws HttpNotFoundException
     * @throws HttpBadRequestException
     */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $this->request = $request;
        $this->response = $response;
        $this->args = $args;
        header('Content-Type: application/json');

        return $this->action();
    }

    public function setSettings(array $appSettings)
    {
        $this->settings = $appSettings;
    }

    /**
     * @return Response
     * @throws HttpBadRequestException
     */
    abstract protected function action(): Response;

    /**
     * @return array|object
     * @throws HttpBadRequestException
     */
    protected function getFormData()
    {
        $input = json_decode(file_get_contents('php://input'));

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new HttpBadRequestException($this->request, 'Malformed JSON input.');
        }

        return $input;
    }

    /**
     * @param string $name
     * @return mixed
     * @throws HttpBadRequestException
     */
    protected function resolveArg(string $name)
    {
        if (!isset($this->args[$name])) {
            throw new HttpBadRequestException($this->request, "Could not resolve argument `{$name}`.");
        }

        return $this->args[$name];
    }

    /**
     * @param object|array|null $data
     * @param int $statusCode
     * @return Response
     */
    protected function respondWithData($data = null, int $statusCode = 200): Response
    {
        $payload = new ActionPayload($statusCode, $data);

        return $this->respond($payload);
    }

    /**
     * @param object|array|null $data
     * @param int $statusCode
     * @return Response Pure prettified json object
     */
    protected function responseRawWithData($data = null, int $statusCode = 200): Response
    {
        $json = json_encode($data, JSON_PRETTY_PRINT);
        $this->response->getBody()->write($json);
        return $this->response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($statusCode);
    }

    /**
     * @param string $type Type de l'erreur à renvoyer
     * @param string $description Description de l'erreur
     * @param int $statusCode Code HTTP de retour
     * @return Response L'objet réponse à retourner au client
     */
    protected function respondWithError(string $type, string $description, int $statusCode = 400): Response
    {
        if ($statusCode === 0) {
            $statusCode = 500;
        }

        $error = new ActionError($type, $description);
        $payload = new ActionPayload($statusCode, null, $error);

        return $this->respond($payload);
    }

    /**
     * @param ActionPayload $payload
     * @return Response
     */
    protected function respond(ActionPayload $payload): Response
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT);
        $this->response->getBody()->write($json);

        return $this->response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($payload->getHttpStatusCode());
    }

    /**
     * Verify all required property are present on the body
     * @param array $body
     * @param array $requiredProperty
     * @return void
     * @throws HttpBadRequestException when the required properties is not present on the body
     */
    protected function checkRequiredProperties(array $body, array $requiredProperty): void
    {
        $result = array_diff($requiredProperty, array_keys($body));
        if (!empty($result)) {
            $exception = new HttpBadRequestException(
                $this->request,
                'Missing required properties : ' . implode(', ', $result)
            );
            $exception->setTitle('missing_properties');
            throw $exception;
        }
    }
}
