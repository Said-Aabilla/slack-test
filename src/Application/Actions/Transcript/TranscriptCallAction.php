<?php

namespace App\Application\Actions\Transcript;

use App\Application\Actions\Action;
use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\Exception\IntegrationException;
use App\Domain\Transcription\Service\Transcript;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;

class TranscriptCallAction extends Action
{

    private Transcript $transcript;

    /**
     * Lance ou relance la transcription d'un appel
     * @param array $call
     * @return array|true[]
     */
    private function transcriptCall(array $call): array
    {
        $callId = $call['call_id'] ?? null;
        $channelId = $call['channel_id'] ?? null;
        $force = $call['force'] ?? false;

        if (empty($callId)) {
            $missingParameterMessage = 'Le <call_id> est obligatoire';
        }

        if (empty($channelId)) {
            $missingParameterMessage = 'Le <channel_id> est obligatoire';
        }

        if (!empty($missingParameterMessage)) {
            return [
                'status'  => false,
                'code'    => 'MISSING_PARAMETER',
                'message' => $missingParameterMessage,
            ];
        }

        try {
            $this->transcript->transcriptCall($callId, $channelId, $force);
            $transcriptResult = [
                'status' => true
            ];
        } catch (IntegrationException $integrationException) {
            $transcriptResult = [
                'status'  => false,
                'code'    => 'INTEGRATION_EXCEPTION',
                'message' => $integrationException->getMessage(),
            ];
        } catch (Exception $exception) {
            $transcriptResult = [
                'status'  => false,
                'code'    => 'UNEXPECTED_EXCEPTION',
                'message' => $exception->getMessage(),
            ];
        }

        return $transcriptResult;
    }

    protected function action(): Response
    {
        $transcriptPayload = $this->request->getParsedBody();

        // On gère le cas où les identifiants de l'appel sont passé au premier niveau du body
        if (isset($transcriptPayload['call_id'], $transcriptPayload['channel_id'])) {
            $callsToTranscripts[] = $transcriptPayload;
        } else {
            $callsToTranscripts = $transcriptPayload;
        }

        $transcriptResult = [];
        foreach ($callsToTranscripts as $callPosition => $call) {
            $transcriptResult[$callPosition] = $this->transcriptCall($call);
        }

        return $this->respondWithData($transcriptResult);
    }

    public function __construct(IntegrationLoggerInterface $logger, Transcript $transcript)
    {
        parent::__construct($logger);
        $this->transcript = $transcript;
    }
}
