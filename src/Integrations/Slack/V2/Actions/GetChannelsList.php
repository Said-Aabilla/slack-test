<?php

namespace App\Integrations\Slack\V2\Actions;

use App\Application\Actions\IntegrationAction;
use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\Integration\AliasMapper;
use App\Integrations\Slack\V2\Service\Slack;
use App\Intrastructure\Persistence\IntegrationRepository;
use DI\Container;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;

class GetChannelsList extends IntegrationAction
{
    private Slack $slack;

    public function __construct(
        AliasMapper                $fakeIntegrationNameMapper,
        IntegrationLoggerInterface $logger,
        Container                  $container,
        IntegrationRepository      $integrationRepository,
        Slack                      $slack
    )
    {
        parent::__construct($fakeIntegrationNameMapper, $logger, $container, $integrationRepository);
        $this->slack = $slack;
    }

    /**
     * @throws Exception
     */
    protected function action(): Response
    {

        $channelsList = [];
        $cursor = '';

        while (empty($channelsList) && empty($cursor) || !empty($channelsList) && !empty($cursor)) {
            try {

                // To get only public channels use a third string param: 'public_channel'
                $channelsResponse = $this->slack->listSlackChannels($this->integration, $cursor);


            } catch (Exception $e) {
                $this->logger->debug('SLACK :: Error listing channels', ['error' => $e->getMessage()]);
                http_response_code(0 !== $e->getCode() ? $e->getCode() : 500);
                echo $e->getMessage();
                exit;
            }

            $channelsList = array_merge($channelsList, $channelsResponse['channels']);
            $cursor = $channelsResponse['next_cursor'];
        }

        $channelsList = array_merge(['' => 'None'], $channelsList);


        $this->response->getBody()->write(json_encode($channelsList));
        return $this->response->withStatus(200);
    }
}
