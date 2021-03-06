<?php

namespace Pipe\Kubernetes;

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Behat\Tester\Exception\PendingException;
use Behat\Gherkin\Node\TableNode;
use ContinuousPipe\Pipe\Kubernetes\Event\NamespaceCreated;
use ContinuousPipe\Pipe\Kubernetes\PrivateImages\SecretFactory;
use ContinuousPipe\Pipe\Kubernetes\Tests\Repository\HookableNamespaceRepository;
use ContinuousPipe\Pipe\Kubernetes\Tests\Repository\HookableServiceAccountRepository;
use ContinuousPipe\Pipe\Kubernetes\Tests\Repository\InMemorySecretRepository;
use ContinuousPipe\Pipe\Kubernetes\Tests\Repository\InMemoryServiceAccountRepository;
use ContinuousPipe\Pipe\Kubernetes\Tests\Repository\Trace\TraceableNamespaceRepository;
use ContinuousPipe\Pipe\Kubernetes\Tests\Repository\Trace\TraceableSecretRepository;
use ContinuousPipe\Pipe\Kubernetes\Tests\Repository\Trace\TraceableServiceAccountRepository;
use ContinuousPipe\Model\Environment;
use ContinuousPipe\Pipe\View\Deployment;
use ContinuousPipe\Pipe\DeploymentContext;
use ContinuousPipe\Pipe\DeploymentRequest;
use ContinuousPipe\Pipe\Tests\MessageBus\TraceableMessageBus;
use ContinuousPipe\Security\Credentials\Bucket;
use ContinuousPipe\Security\User\User;
use Kubernetes\Client\Exception\ClientError;
use Kubernetes\Client\Exception\NamespaceNotFound;
use Kubernetes\Client\Exception\ServiceAccountNotFound;
use Kubernetes\Client\Model\KubernetesNamespace;
use Kubernetes\Client\Model\LocalObjectReference;
use Kubernetes\Client\Model\ObjectMetadata;
use Kubernetes\Client\Model\Secret;
use Kubernetes\Client\Model\ServiceAccount;
use Kubernetes\Client\Model\Status;
use LogStream\LoggerFactory;
use Symfony\Component\HttpFoundation\Request;

class NamespaceContext implements Context
{
    /**
     * @var \Pipe\EnvironmentContext
     */
    private $environmentContext;

    /**
     * @var TraceableNamespaceRepository
     */
    private $namespaceRepository;

    /**
     * @var TraceableMessageBus
     */
    private $eventBus;

    /**
     * @var TraceableSecretRepository
     */
    private $secretRepository;

    /**
     * @var TraceableServiceAccountRepository
     */
    private $serviceAccountRepository;
    /**
     * @var LoggerFactory
     */
    private $loggerFactory;
    /**
     * @var InMemoryServiceAccountRepository
     */
    private $inMemoryServiceAccountRepository;
    /**
     * @var HookableServiceAccountRepository
     */
    private $hookableServiceAccountRepository;
    /**
     * @var HookableNamespaceRepository
     */
    private $hookableNamespaceRepository;
    /**
     * @var InMemorySecretRepository
     */
    private $inMemorySecretRepository;

    /**
     * @param TraceableNamespaceRepository $namespaceRepository
     * @param TraceableMessageBus $eventBus
     * @param TraceableSecretRepository $secretRepository
     * @param TraceableServiceAccountRepository $serviceAccountRepository
     * @param LoggerFactory $loggerFactory
     * @param InMemoryServiceAccountRepository $inMemoryServiceAccountRepository
     * @param HookableServiceAccountRepository $hookableServiceAccountRepository
     * @param HookableNamespaceRepository $hookableNamespaceRepository
     */
    public function __construct(
        TraceableNamespaceRepository $namespaceRepository,
        TraceableMessageBus $eventBus,
        TraceableSecretRepository $secretRepository,
        TraceableServiceAccountRepository $serviceAccountRepository,
        LoggerFactory $loggerFactory,
        InMemoryServiceAccountRepository $inMemoryServiceAccountRepository,
        InMemorySecretRepository $inMemorySecretRepository,
        HookableServiceAccountRepository $hookableServiceAccountRepository,
        HookableNamespaceRepository $hookableNamespaceRepository
    )
    {
        $this->namespaceRepository = $namespaceRepository;
        $this->eventBus = $eventBus;
        $this->secretRepository = $secretRepository;
        $this->serviceAccountRepository = $serviceAccountRepository;
        $this->loggerFactory = $loggerFactory;
        $this->inMemoryServiceAccountRepository = $inMemoryServiceAccountRepository;
        $this->hookableServiceAccountRepository = $hookableServiceAccountRepository;
        $this->hookableNamespaceRepository = $hookableNamespaceRepository;
        $this->inMemorySecretRepository = $inMemorySecretRepository;
    }

    /**
     * @BeforeScenario
     */
    public function gatherContexts(BeforeScenarioScope $scope)
    {
        $this->environmentContext = $scope->getEnvironment()->getContext('Pipe\EnvironmentContext');
    }

    /**
     * @Then it should create a new namespace
     */
    public function itShouldCreateANewNamespace()
    {
        $numberOfCreatedNamespaces = count($this->namespaceRepository->getCreated());

        if ($numberOfCreatedNamespaces == 0) {
            throw new \RuntimeException('No namespace were created');
        }
    }

    /**
     * @Given I have a namespace :name
     * @Given there is a namespace :name
     */
    public function iHaveANamespace($name)
    {
        try {
            $namespace = $this->namespaceRepository->findOneByName($name);
        } catch (NamespaceNotFound $e) {
            $namespace = $this->namespaceRepository->create(new KubernetesNamespace(new ObjectMetadata($name)));
            $this->namespaceRepository->clear();
        }

        return $namespace;
    }

    /**
     * @Given the namespace :name is in deletion
     */
    public function theNamespaceIsInDeletion($name)
    {
        $this->hookableNamespaceRepository->addDeleteHooks(function(KubernetesNamespace $namespace) use ($name) {
            if ($namespace->getMetadata()->getName() != $name) {
                return $namespace;
            }

            throw new ClientError(new Status(
                'Failure',
                'Operation cannot be fulfilled on namespaces "'.$name.'": The system is ensuring all content is removed from this namespace.  Upon completion, this namespace will automatically be purged by the system.',
                '',
                409
            ));
        });
    }

    /**
     * @Given the service account :name to not contain any docker registry pull secret
     */
    public function theServiceAccountToNotContainAnyDockerRegistryPullSecret($name)
    {
        try {
            $serviceAccount = $this->serviceAccountRepository->findByName($name);

            $this->serviceAccountRepository->update(new ServiceAccount(
                $serviceAccount->getMetadata(),
                $serviceAccount->getSecrets(),
                []
            ));
        } catch (ServiceAccountNotFound $e) {
            $this->serviceAccountRepository->create(new ServiceAccount(
                new ObjectMetadata($name),
                [],
                []
            ));
        }
    }

    /**
     * @Given the default service account won't be created at the same time than the namespace
     */
    public function theDefaultServiceAccountWonTBeCreatedAtTheSameTimeThanTheNamespace()
    {
        $calls = 0;
        $this->hookableServiceAccountRepository->addFindByNameHook(function(ServiceAccount $serviceAccount) use (&$calls) {
            if ($calls++ < 2) {
                throw new ServiceAccountNotFound('Service account not found');
            }

            return $serviceAccount;
        });
    }

    /**
     * @Then it should not create any namespace
     */
    public function itShouldNotCreateAnyNamespace()
    {
        $numberOfCreatedNamespaces = count($this->namespaceRepository->getCreated());

        if ($numberOfCreatedNamespaces !== 0) {
            throw new \RuntimeException(sprintf(
                'Expected 0 namespace to be created, got %d',
                $numberOfCreatedNamespaces
            ));
        }
    }

    /**
     * @Then it should dispatch the namespace created event
     */
    public function itShouldDispatchTheNamespaceCreatedEvent()
    {
        $namespaceCreatedEvents = array_filter($this->eventBus->getMessages(), function ($message) {
            return $message instanceof NamespaceCreated;
        });

        if (count($namespaceCreatedEvents) == 0) {
            throw new \RuntimeException('Expected to found a namespace created event, found 0');
        }
    }

    /**
     * @Then a docker registry secret should be created
     */
    public function aDockerRegistrySecretShouldBeCreated()
    {
        $matchingCreated = array_filter($this->secretRepository->getCreated(), function (Secret $secret) {
            return $this->isPrivateSecretName($secret->getMetadata()->getName());
        });

        if (count($matchingCreated) == 0) {
            throw new \RuntimeException('No docker registry secret found');
        }
    }

    /**
     * @Then the service account should be updated with a docker registry pull secret
     */
    public function theServiceAccountShouldBeUpdatedWithADockerRegistryPullSecret()
    {
        $matchingServiceAccounts = array_filter($this->serviceAccountRepository->getUpdated(), function (ServiceAccount $serviceAccount) {
            $matchingImagePulls = array_filter($serviceAccount->getImagePullSecrets(), function (LocalObjectReference $objectReference) {
                return $this->isPrivateSecretName($objectReference->getName());
            });

            return count($matchingImagePulls) > 0;
        });

        if (count($matchingServiceAccounts) == 0) {
            throw new \RuntimeException('No updated service account with docker registry pull secret found');
        }
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    private function isPrivateSecretName($name)
    {
        return substr($name, 0, strlen(SecretFactory::SECRET_PREFIX)) == SecretFactory::SECRET_PREFIX;
    }

    /**
     * @Then the secret :name should be created
     */
    public function theSecretShouldBeCreated($name)
    {
        $matchingSecrets = array_filter($this->secretRepository->getCreated(), function(Secret $secret) use ($name) {
            return $secret->getMetadata()->getName() == $name;
        });

        if (count($matchingSecrets) == 0) {
            throw new \RuntimeException(sprintf(
                'No secret named "%s" found is list of created secrets',
                $name
            ));
        }
    }

    /**
     * @Then the secret :name should not be created
     */
    public function theSecretShouldNotBeCreated($name)
    {
        $matchingSecrets = array_filter($this->secretRepository->getCreated(), function(Secret $secret) use ($name) {
            return $secret->getMetadata()->getName() == $name;
        });

        if (count($matchingSecrets) != 0) {
            throw new \RuntimeException(sprintf(
                'Found %d matching secrets in the list of created secrets',
                count($matchingSecrets)
            ));
        }
    }

    /**
     * @Given the secret of type :type named :name already exists with the following data:
     */
    public function theSecretAlreadyExists($type, $name, TableNode $table)
    {
        $this->inMemorySecretRepository->create(new Secret(
            new ObjectMetadata($name),
            $table->getRowsHash(),
            $type
        ));
    }

    /**
     * @Then the secret :name should not be updated
     */
    public function theSecretShouldNotBeUpdated($name)
    {
        $matchingSecrets = array_filter($this->secretRepository->getUpdated(), function(Secret $secret) use ($name) {
            return $secret->getMetadata()->getName() == $name;
        });

        if (count($matchingSecrets) != 0) {
            throw new \RuntimeException(sprintf(
                '%d secrets found is list of updated secrets',
                count($matchingSecrets)
            ));
        }
    }

    /**
     * @Then the secret :name should be updated
     */
    public function theSecretShouldBeUpdated($name)
    {
        $matchingSecrets = array_filter($this->secretRepository->getUpdated(), function(Secret $secret) use ($name) {
            return $secret->getMetadata()->getName() == $name;
        });

        if (count($matchingSecrets) == 0) {
            throw new \RuntimeException('No matching secret found');
        }
    }

    /**
     * @Then the namespace :name should be deleted
     */
    public function theNamespaceShouldBeDeleted($name)
    {
        $matchingNamespaces = array_filter($this->namespaceRepository->getDeleted(), function(KubernetesNamespace $namespace) use ($name) {
            return $namespace->getMetadata()->getName() == $name;
        });

        if (count($matchingNamespaces) == 0) {
            throw new \RuntimeException(sprintf(
                'No namespace named "%s" found is list of deleted namespaces',
                $name
            ));
        }
    }

    /**
     * @Then the namespace :name should be created
     */
    public function theNamespaceShouldBeCreated($name)
    {
        $matchingNamespaces = array_filter($this->namespaceRepository->getCreated(), function(KubernetesNamespace $namespace) use ($name) {
            return $namespace->getMetadata()->getName() == $name;
        });

        if (count($matchingNamespaces) == 0) {
            throw new \RuntimeException(sprintf(
                'No namespace named "%s" found is list of created namespaces',
                $name
            ));
        }
    }

    /**
     * @Then the namespace :name should have the label :key that contains :value
     */
    public function theNamespaceShouldHaveTheLabelThatContains($name, $key, $value)
    {
        $namespace = $this->namespaceRepository->findOneByName($name);
        $rawLabels = $namespace->getMetadata()->getLabelList()->toAssociativeArray();

        if (!array_key_exists($key, $rawLabels)) {
            throw new \RuntimeException(sprintf(
                'Label "%s" expected to be found in the label list',
                $key
            ));
        }

        if ($rawLabels[$key] != $value) {
            throw new \RuntimeException(sprintf(
                'Expected label "%s" to have the value "%s" but found "%s"',
                $key,
                $value,
                $rawLabels[$key]
            ));
        }
    }
}
