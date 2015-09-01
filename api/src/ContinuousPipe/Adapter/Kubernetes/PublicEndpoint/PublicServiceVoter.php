<?php

namespace ContinuousPipe\Adapter\Kubernetes\PublicEndpoint;

use Kubernetes\Client\Model\KubernetesObject;
use Kubernetes\Client\Model\Service;
use Kubernetes\Client\Model\ServiceSpecification;

class PublicServiceVoter
{
    /**
     * Return true if this is a public service.
     *
     * @param KubernetesObject $object
     *
     * @return bool
     */
    public function isAPublicService(KubernetesObject $object)
    {
        return $object instanceof Service && $object->getSpecification()->getType() == ServiceSpecification::TYPE_LOAD_BALANCER;
    }
}
