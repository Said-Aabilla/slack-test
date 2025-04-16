<?php

namespace App\Application\Actions\Metric;

use App\Application\Actions\Action;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\APC;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Stream;

class GetMetricsAction extends Action
{
    protected function action(): Response
    {
        if (
            class_exists(CollectorRegistry::class) &&
            class_exists(APC::class)
        ) {
            $collectorRegistry = new CollectorRegistry(new APC());
            $renderer = new RenderTextFormat();
            $metricFamilySamples = $renderer->render($collectorRegistry->getMetricFamilySamples());
        }

        $body = new Stream(fopen('php://temp', 'r+'));
        $body->write($metricFamilySamples ?? '');
        return $this->response
            ->withHeader('Content-Type', 'text/plain')
            ->withBody($body);
    }
}