<?php

declare(strict_types=1);

namespace App\Application\Actions;

use JsonSerializable;

class ActionPayload implements JsonSerializable
{
    /**
     * @var int
     */
    private int $httpStatusCode;

    /**
     * @var array|object|null
     */
    private $data;

    /**
     * @var ActionError|null
     */
    private ?ActionError $error;

    /**
     * @param int $httpStatusCode
     * @param object|array|null $data
     * @param ActionError|null $error
     */
    public function __construct(
        int $httpStatusCode = 200,
        $data = null,
        ?ActionError $error = null
    ) {
        $this->httpStatusCode = $httpStatusCode;
        $this->data = $data;
        $this->error = $error;
    }

    /**
     * @return int
     */
    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    /**
     * @return array|null|object
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @return ActionError|null
     */
    public function getError(): ?ActionError
    {
        return $this->error;
    }

    /**
     * @return array
     */
    public function jsonSerialize(): array
    {
        $payload = [
            'http_status_code' => $this->httpStatusCode,
        ];

        if ($this->data !== null) {
            $payload['data'] = $this->data;
        } elseif ($this->error !== null) {
            $payload['error'] = $this->error;
        }

        return $payload;
    }
}
