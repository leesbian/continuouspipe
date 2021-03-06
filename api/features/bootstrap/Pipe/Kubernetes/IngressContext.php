<?php

namespace Pipe\Kubernetes;

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\TableNode;
use ContinuousPipe\Pipe\Kubernetes\Tests\Repository\HookableIngressRepository;
use ContinuousPipe\Pipe\Kubernetes\Tests\Repository\Trace\TraceableIngressRepository;
use Kubernetes\Client\Model\Ingress;
use Kubernetes\Client\Model\IngressBackend;
use Kubernetes\Client\Model\IngressHttpRule;
use Kubernetes\Client\Model\IngressHttpRulePath;
use Kubernetes\Client\Model\IngressRule;
use Kubernetes\Client\Model\IngressSpecification;
use Kubernetes\Client\Model\IngressStatus;
use Kubernetes\Client\Model\KeyValueObjectList;
use Kubernetes\Client\Model\Label;
use Kubernetes\Client\Model\LoadBalancerIngress;
use Kubernetes\Client\Model\LoadBalancerStatus;
use Kubernetes\Client\Model\ObjectMetadata;
use Kubernetes\Client\Repository\IngressRepository;

class IngressContext implements Context
{
    /**
     * @var TraceableIngressRepository
     */
    private $traceableIngressRepository;

    /**
     * @var IngressRepository
     */
    private $ingressRepository;

    /**
     * @var HookableIngressRepository
     */
    private $hookableIngressRepository;

    /**
     * @param TraceableIngressRepository $traceableIngressRepository
     * @param HookableIngressRepository $hookableIngressRepository
     * @param IngressRepository $ingressRepository
     */
    public function __construct(TraceableIngressRepository $traceableIngressRepository, HookableIngressRepository $hookableIngressRepository, IngressRepository $ingressRepository)
    {
        $this->traceableIngressRepository = $traceableIngressRepository;
        $this->hookableIngressRepository = $hookableIngressRepository;
        $this->ingressRepository = $ingressRepository;
    }

    /**
     * @Then the ingress named :name should be created
     */
    public function theIngressNamedShouldBeCreated($name)
    {
        $created = $this->traceableIngressRepository->getCreated();
        $matchingIngresses = array_filter($created, function(Ingress $ingress) use ($name) {
            return $ingress->getMetadata()->getName() == $name;
        });

        if (count($matchingIngresses) == 0) {
            throw new \RuntimeException('No ingress found');
        }
    }

    /**
     * @Then the ingress named :name should not be created
     */
    public function theIngressNamedShouldNotBeCreated($name)
    {
        $created = $this->traceableIngressRepository->getCreated();
        $matchingIngresses = array_filter($created, function(Ingress $ingress) use ($name) {
            return $ingress->getMetadata()->getName() == $name;
        });

        if (count($matchingIngresses) != 0) {
            throw new \RuntimeException('Ingress found');
        }
    }

    /**
     * @Then the ingress named :name should have the hostname :hostname
     */
    public function theIngressNamedShouldHaveTheHostname($name, $hostname)
    {
        $ingress = $this->ingressRepository->findOneByName($name);

        foreach ($ingress->getSpecification()->getRules() as $rule) {
            if ($rule->getHost() == $hostname) {
                return;
            }
        }

        throw new \RuntimeException('No rule matching the hostname found');
    }

    /**
     * @Then the ingress named :name should have the class :class
     */
    public function theIngressNamedShouldHaveTheClass($name, $class)
    {
        $ingress = $this->ingressRepository->findOneByName($name);
        $annotation = $ingress->getMetadata()->getAnnotationList()->get('kubernetes.io/ingress.class');

        if (null === $annotation) {
            throw new \RuntimeException('Class annotation not found');
        }

        if ($annotation->getValue() != $class) {
            throw new \RuntimeException(sprintf(
                'Found class "%s" instead',
                $annotation->getValue()
            ));
        }
    }

    /**
     * @Then the ingress named :name should have the backend service :service on port :port
     */
    public function theIngressNamedShouldHaveTheBackendServiceOnPort($name, $service, $port)
    {
        $ingress = $this->ingressRepository->findOneByName($name);

        foreach ($ingress->getSpecification()->getRules() as $rule) {
            foreach ($rule->getHttp()->getPaths() as $path) {
                if ($path->getBackend()->getServiceName() == $service && $path->getBackend()->getServicePort() == $port) {
                    return;
                }
            }
        }

        throw new \RuntimeException('The backend was not found in the rules\' paths');
    }

    /**
     * @Then the ingress named :name should not be using secure backends
     */
    public function theIngressNamedShouldNotBeUsingSecureBackends($name)
    {
        $ingress = $this->ingressRepository->findOneByName($name);
        $annotation = $ingress->getMetadata()->getAnnotationList()->get('ingress.kubernetes.io/secure-backends');

        if (null !== $annotation && $annotation->getValue() == 'true') {
            throw new \RuntimeException('It is apparently using the secure backends!');
        }
    }

    /**
     * @Then the ingress named :name should be using secure backends
     */
    public function theIngressNamedShouldBeUsingSecureBackends($name)
    {
        $ingress = $this->ingressRepository->findOneByName($name);
        $annotation = $ingress->getMetadata()->getAnnotationList()->get('ingress.kubernetes.io/secure-backends');

        if (null === $annotation || $annotation->getValue() != 'true') {
            throw new \RuntimeException('It is apparently NOT using the secure backends!');
        }
    }

    /**
     * @Then the ingress named :name should have a SSL certificate for the host :host
     */
    public function theIngressNamedShouldHaveASslCertificateForTheHost($name, $host)
    {
        $ingress = $this->ingressRepository->findOneByName($name);

        foreach ($ingress->getSpecification()->getTls() as $tls) {
            if (null === $tls->getHosts()) {
                continue;
            }

            foreach ($tls->getHosts() as $tlsHost) {
                if ($tlsHost == $host) {
                    return $tls;
                }
            }
        }

        throw new \RuntimeException('No TLS certificate found for this host');
    }

    /**
     * @Then the ingress named :name should have :count SSL certificate
     */
    public function theIngressNamedShouldHaveSslCertificate($name, $count)
    {
        $ingress = $this->ingressRepository->findOneByName($name);
        $numberOfCertificates = count($ingress->getSpecification()->getTls());

        if ($count != $numberOfCertificates) {
            throw new \RuntimeException(sprintf(
                'Expected %d certificates but found %d',
                $count,
                $numberOfCertificates
            ));
        }
    }

    /**
     * @Given the ingress :name will be created with the public DNS address :address
     */
    public function theIngressWillBeCreatedWithThePublicDnsAddress($name, $address)
    {
        $this->theIngressWillBeCreatedWithTheStatus($name, new LoadBalancerIngress(null, $address));
    }

    /**
     * @Given the ingress :name will be created with the public IP :ip
     */
    public function theIngressWillBeCreatedWithThePublicIp($name, $ip)
    {
        $this->theIngressWillBeCreatedWithTheStatus($name, new LoadBalancerIngress($ip));
    }

    /**
     * @Given there is an ingress :name with the hostname :hostname in the first rule with the following labels:
     */
    public function thereIsAnIngressWithTheHostnameInTheFirstRule($name, $hostname, TableNode $table)
    {
        $labels = array_map(function(array $row) {
            return new Label($row['name'], $row['value']);
        }, $table->getHash());

        $this->ingressRepository->create(new Ingress(
            new ObjectMetadata($name, new KeyValueObjectList($labels)),
            new IngressSpecification(
                null,
                [],
                [
                    new IngressRule($hostname, new IngressHttpRule([
                        new IngressHttpRulePath(new IngressBackend('app', 80)),
                    ]))
                ]
            )
        ));
    }

    /**
     * @param $name
     * @param $status
     */
    private function theIngressWillBeCreatedWithTheStatus($name, $status)
    {
        $this->hookableIngressRepository->addFindOneByNameHooks(function (Ingress $ingress) use ($name, $status) {
            if ($ingress->getMetadata()->getName() == $name) {
                $ingress = new Ingress(
                    $ingress->getMetadata(),
                    $ingress->getSpecification(),
                    new IngressStatus(new LoadBalancerStatus([
                        $status
                    ]))
                );
            }

            return $ingress;
        });
    }

    /**
     * @Then the ingress named :name should not have a backend service
     */
    public function theIngressNamedShouldNotHaveABackendService($name)
    {
        $ingress = $this->ingressRepository->findOneByName($name);

        if ($ingress->getSpecification()->getBackend() !== null) {
            throw new \RuntimeException('A backend was found for the ingress');
        }
    }

    /**
     * @Then the ingress named :name should have the backend service :service on port :port behind the rule :rule
     */
    public function theIngressNamedShouldHaveTheBackendServiceOnPortBehindTheRule($name, $service, $port, $rule)
    {
        $hostFromRule = function (IngressRule $rule) {
            return $rule->getHost();
        };

        $pathsFromRule = function (IngressRule $rule) {
            return $rule->getHttp()->getPaths();
        };

        $backendFromPath = function(IngressHttpRulePath $path) {
            return [$path->getBackend()->getServiceName(), $path->getBackend()->getServicePort()];
        };

        $rules = $this->ingressRepository->findOneByName($name)->getSpecification()->getRules();

        $processedRules = array_combine(
            array_map($hostFromRule, $rules),
            array_map($pathsFromRule, $rules)
        );
        
        if (!isset($processedRules[$rule])) {
            throw new \RuntimeException('Ingress has no rule '.$rule);
        }

        $backends = array_map($backendFromPath, $processedRules[$rule]);

        if (!in_array([$service, $port], $backends)) {
            throw new \RuntimeException(sprintf(
                'Ingress has no backend service %s on port %s behind the rule %s', $service, $port, $rule
            ));
        }
    }
}
