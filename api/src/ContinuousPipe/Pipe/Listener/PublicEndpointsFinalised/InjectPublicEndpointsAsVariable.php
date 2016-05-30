<?php

namespace ContinuousPipe\Pipe\Listener\PublicEndpointsFinalised;

use ContinuousPipe\Model\Component\EnvironmentVariable;
use ContinuousPipe\Pipe\Environment\PublicEndpoint;
use ContinuousPipe\Pipe\Event\PublicEndpointsFinalised;

class InjectPublicEndpointsAsVariable
{
    /**
     * @param PublicEndpointsFinalised $event
     */
    public function notify(PublicEndpointsFinalised $event)
    {
        $context = $event->getDeploymentContext();
        $components = $context->getEnvironment()->getComponents();
        $publicEndpoints = $event->getEndpoints();

        foreach ($components as $component) {
            $specification = $component->getSpecification();
            $environmentVariables = $specification->getEnvironmentVariables();

            foreach ($publicEndpoints as $publicEndpoint) {
                $environmentVariables[] = $this->getServiceEnvironmentVariableForEndpoint($publicEndpoint);
                $environmentVariables[] = $this->getEndpointEnvironmentVariableForEndpoint($publicEndpoint);
            }

            $specification->setEnvironmentVariables(
                $this->replaceEnvironmentVariableParameters($environmentVariables)
            );
        }
    }

    /**
     * @param PublicEndpoint $endpoint
     *
     * @return EnvironmentVariable
     */
    private function getServiceEnvironmentVariableForEndpoint(PublicEndpoint $endpoint)
    {
        return new EnvironmentVariable(
            sprintf('SERVICE_%s_PUBLIC_ENDPOINT', strtoupper($endpoint->getName())),
            $endpoint->getAddress()
        );
    }

    /**
     * @param PublicEndpoint $endpoint
     *
     * @return EnvironmentVariable
     */
    private function getEndpointEnvironmentVariableForEndpoint(PublicEndpoint $endpoint)
    {
        return new EnvironmentVariable(
            sprintf('ENDPOINT_%s_PUBLIC_ENDPOINT', strtoupper($endpoint->getName())),
            $endpoint->getAddress()
        );
    }

    /**
     * @param EnvironmentVariable[] $environmentVariables
     *
     * @return EnvironmentVariable[]
     */
    private function replaceEnvironmentVariableParameters(array $environmentVariables)
    {
        $mapping = [];
        foreach ($environmentVariables as $environmentVariable) {
            $mapping['${'.$environmentVariable->getName().'}'] = $environmentVariable->getValue();
        }

        $replacedVariables = [];
        foreach ($environmentVariables as $environmentVariable) {
            $replacedVariables[] = new EnvironmentVariable(
                $environmentVariable->getName(),
                str_replace(array_keys($mapping), array_values($mapping), $environmentVariable->getValue())
            );
        }

        return $replacedVariables;
    }
}
