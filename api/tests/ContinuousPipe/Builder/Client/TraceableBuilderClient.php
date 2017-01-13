<?php

namespace ContinuousPipe\Builder\Client;

use ContinuousPipe\Builder\Request\BuildRequest;
use ContinuousPipe\Security\User\User;

class TraceableBuilderClient implements BuilderClient
{
    /**
     * @var BuilderClient
     */
    private $decoratedClient;

    /**
     * @var BuildRequest[]
     */
    private $requests = [];

    /**
     * @param BuilderClient $decoratedClient
     */
    public function __construct(BuilderClient $decoratedClient)
    {
        $this->decoratedClient = $decoratedClient;
    }

    /**
     * {@inheritdoc}
     */
    public function build(BuildRequest $buildRequest, User $user)
    {
        $this->requests[] = $buildRequest;

        return $this->decoratedClient->build($buildRequest, $user);
    }

    /**
     * @return BuildRequest[]
     */
    public function getRequests(): array
    {
        return $this->requests;
    }
}
