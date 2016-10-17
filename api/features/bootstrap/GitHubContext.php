<?php

use Behat\Behat\Context\Context;
use Behat\Behat\Tester\Exception\PendingException;
use Behat\Gherkin\Node\PyStringNode;
use ContinuousPipe\River\CodeRepository\GitHub\CodeReferenceResolver;
use ContinuousPipe\River\Event\GitHub\CommentedTideFeedback;
use ContinuousPipe\River\Event\TideCreated;
use ContinuousPipe\River\EventBus\EventStore;
use ContinuousPipe\River\Notifications\GitHub\CommitStatus\GitHubStateResolver;
use ContinuousPipe\River\Notifications\TraceableNotifier;
use ContinuousPipe\River\Tests\CodeRepository\GitHub\TestHttpClient;
use ContinuousPipe\River\Tests\CodeRepository\Status\FakeCodeStatusUpdater;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use ContinuousPipe\River\Tests\CodeRepository\GitHub\FakePullRequestDeploymentNotifier;
use ContinuousPipe\River\Tests\CodeRepository\GitHub\FakePullRequestResolver;
use ContinuousPipe\River\Tests\Pipe\TraceableClient;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class GitHubContext implements Context
{
    /**
     * @var TideContext
     */
    private $tideContext;

    /**
     * @var FlowContext
     */
    private $flowContext;

    /**
     * @var Kernel
     */
    private $kernel;

    /**
     * @var FakePullRequestResolver
     */
    private $fakePullRequestResolver;

    /**
     * @var Response
     */
    private $response;
    /**
     * @var TraceableClient
     */
    private $traceableClient;
    /**
     * @var EventStore
     */
    private $eventStore;
    /**
     * @var TestHttpClient
     */
    private $gitHubHttpClient;
    /**
     * @var TraceableNotifier
     */
    private $gitHubTraceableNotifier;

    /**
     * @param Kernel $kernel
     * @param TraceableNotifier $gitHubTraceableNotifier
     * @param FakePullRequestResolver $fakePullRequestResolver
     * @param TraceableClient $traceableClient
     * @param EventStore $eventStore
     * @param TestHttpClient $gitHubHttpClient
     */
    public function __construct(Kernel $kernel, TraceableNotifier $gitHubTraceableNotifier, FakePullRequestResolver $fakePullRequestResolver, TraceableClient $traceableClient, EventStore $eventStore, TestHttpClient $gitHubHttpClient)
    {
        $this->kernel = $kernel;
        $this->fakePullRequestResolver = $fakePullRequestResolver;
        $this->traceableClient = $traceableClient;
        $this->eventStore = $eventStore;
        $this->gitHubHttpClient = $gitHubHttpClient;
        $this->gitHubTraceableNotifier = $gitHubTraceableNotifier;
    }

    /**
     * @BeforeScenario
     */
    public function gatherContexts(BeforeScenarioScope $scope)
    {
        $this->tideContext = $scope->getEnvironment()->getContext('TideContext');
        $this->flowContext = $scope->getEnvironment()->getContext('FlowContext');
    }

    /**
     * @Given the created GitHub comment will have the ID :id
     */
    public function theCreatedGithubCommentWillHaveTheId($id)
    {
        $this->gitHubHttpClient->addHook(function($path, $body, $httpMethod) use ($id) {
            if ('POST' === $httpMethod && preg_match('#issues/([0-9]+)/comments$#', $path)) {
                return new \Guzzle\Http\Message\Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode(['id' => $id])
                );
            }
        });
    }

    /**
     * @Then the GitHub commit status should be :status
     */
    public function theGitHubCommitStatusShouldBe($status)
    {
        $foundStatus = $this->getLastGitHubNotification();
        $foundState = (new GitHubStateResolver())->fromStatus($foundStatus);

        if ($status !== $foundState) {
            throw new \RuntimeException(sprintf(
                'Found status "%s" instead of expected "%s"',
                $foundStatus->getState(),
                $status
            ));
        }
    }

    /**
     * @Then the GitHub commit status should not be updated
     */
    public function theGitHubCommitStatusShouldNotBeUpdated()
    {
        $notifications = $this->gitHubTraceableNotifier->getNotifications();

        if (count($notifications) > 0) {
            throw new \RuntimeException('Found GitHub status notification(s)');
        }
    }

    /**
     * @Then the GitHub commit status description should be:
     */
    public function theGithubCommitStatusDescriptionShouldBeTaskFailed(PyStringNode $string)
    {
        $foundStatus = $this->getLastGitHubNotification();
        $description = $string->getRaw();

        if ($description !== $foundStatus->getDescription()) {
            throw new \RuntimeException(sprintf(
                'Found description "%s" instead of expected "%s"',
                $foundStatus->getDescription(),
                $description
            ));
        }
    }

    /**
     * @Then the GitHub commit status should not be set
     */
    public function theGitHubCommitStatusShouldNotBeSet()
    {
        if (count($this->gitHubTraceableNotifier->getNotifications()) !== 0) {
            throw new \RuntimeException('Expected no status for tide');
        }
    }

    /**
     * @When the commit :sha is pushed to the branch :branch
     */
    public function theCommitIsPushedToTheBranch($sha, $branch)
    {
        $contents = \GuzzleHttp\json_decode(file_get_contents(__DIR__.'/../fixtures/push-master.json'), true);
        $contents['ref'] = 'refs/heads/'.$branch;
        $contents['after'] = $sha;
        $contents['head_commit']['id'] = $sha;

        $this->sendWebHook('push', json_encode($contents));
    }

    /**
     * @When the pull request #:number is opened
     */
    public function thePullRequestIsOpened($number)
    {
        $contents = \GuzzleHttp\json_decode(file_get_contents(__DIR__.'/../fixtures/pull_request-created.json'), true);
        $contents['number'] = $number;

        $this->sendWebHook('pull_request', json_encode($contents));
    }

    /**
     * @When the pull request #:number is opened with head :branch and the commit :sha
     */
    public function thePullRequestIsOpenedWithHeadAndTheCommit($number, $branch, $sha)
    {
        $contents = \GuzzleHttp\json_decode(file_get_contents(__DIR__.'/../fixtures/pull_request-created.json'), true);
        $contents['number'] = $number;
        $contents['pull_request']['head']['ref'] = $branch;
        $contents['pull_request']['head']['label'] = $branch;
        $contents['pull_request']['head']['sha'] = $sha;

        $this->sendWebHook('pull_request', json_encode($contents));
    }

    /**
     * @When the pull request #:number is opened with head :reference from another repository labelled :repositoryLabel
     */
    public function thePullRequestIsOpenedWithHeadFromAnotherRepositoryLabelled($number, $reference, $repositoryLabel)
    {
        $contents = \GuzzleHttp\json_decode(file_get_contents(__DIR__.'/../fixtures/pull_request-created.json'), true);

        $contents['number'] = $number;
        $contents['pull_request']['head']['ref'] = $reference;
        $contents['pull_request']['head']['sha'] = sha1($reference);
        $contents['pull_request']['head']['label'] = $repositoryLabel.':'.$reference;
        $contents['pull_request']['head']['repo']['id'] = rand(10000, 99999);

        $this->sendWebHook('pull_request', json_encode($contents));
    }

    /**
     * @When the pull request #:number is synchronized with head :branch and the commit :sha
     */
    public function thePullRequestIsSynchronizedWithHeadAndTheCommit($number, $branch, $sha)
    {
        $contents = \GuzzleHttp\json_decode(file_get_contents(__DIR__.'/../fixtures/pull_request-created.json'), true);
        $contents['action'] = 'synchronize';
        $contents['number'] = $number;
        $contents['pull_request']['head']['ref'] = $branch;
        $contents['pull_request']['head']['label'] = $branch;
        $contents['pull_request']['head']['sha'] = $sha;

        $this->sendWebHook('pull_request', json_encode($contents));
    }

    /**
     * @When the pull request #:number is synchronized
     */
    public function thePullRequestIsSynchronized($number)
    {
        $contents = \GuzzleHttp\json_decode(file_get_contents(__DIR__.'/../fixtures/pull_request-created.json'), true);
        $contents['number'] = $number;
        $contents['action'] = 'synchronize';

        $this->sendWebHook('pull_request', json_encode($contents));
    }

    /**
     * @When the pull request #:number is labeled
     */
    public function thePullRequestIsLabeled($number)
    {
        $contents = \GuzzleHttp\json_decode(file_get_contents(__DIR__.'/../fixtures/pull_request-created.json'), true);
        $contents['number'] = $number;
        $contents['action'] = 'labeled';

        $this->sendWebHook('pull_request', json_encode($contents));
    }

    /**
     * @When the pull request #:number is labeled with head :branch and the commit :sha
     */
    public function thePullRequestIsLabeledWithHeadAndTheCommand($number, $branch, $sha)
    {
        $contents = \GuzzleHttp\json_decode(file_get_contents(__DIR__.'/../fixtures/pull_request-created.json'), true);
        $contents['number'] = $number;
        $contents['action'] = 'labeled';
        $contents['pull_request']['head']['ref'] = $branch;
        $contents['pull_request']['head']['label'] = $branch;
        $contents['pull_request']['head']['sha'] = $sha;

        $this->sendWebHook('pull_request', json_encode($contents));
    }

    /**
     * @Given the pull request #:number have the label :label
     */
    public function thePullRequestHaveTheLabel($number, $label)
    {
        $this->thePullRequestHaveTheLabels($number, $label);
    }

    /**
     * @Given the pull request #:number have the labels :labelsString
     */
    public function thePullRequestHaveTheLabels($number, $labelsString)
    {
        $this->gitHubHttpClient->addHook(function($path, $body, $httpMethod, $headers) use ($number, $labelsString) {
            if ($httpMethod == 'GET' && preg_match('#/issues/'.$number.'/labels$#', $path)) {
                $labels = array_map(function($label) use ($path) {
                    return [
                        'url' => $path.'/'.urlencode($label),
                        'name' => $label,
                        'color' => 'ffffff',
                    ];
                }, explode(',', $labelsString));

                return new \Guzzle\Http\Message\Response(
                    200,
                    [
                        'Content-Type' => 'application/json',
                    ],
                    json_encode($labels)
                );
            }
        });
    }

    /**
     * @When a push webhook is received
     */
    public function aPushWebhookIsReceived()
    {
        $contents = file_get_contents(__DIR__.'/../fixtures/push-master.json');

        $this->sendWebHook('push', $contents);
    }

    /**
     * @When a status webhook is received with the context :context and the value :state
     */
    public function aStatusWebhookIsReceivedWithTheContextAndTheValue($context, $state)
    {
        $decoded = json_decode(file_get_contents(__DIR__.'/../fixtures/status-pending.json'), true);
        $decoded['context'] = $context;
        $decoded['state'] = $state;

        /** @var TideCreated $tideCreatedEvent */
        $tideCreatedEvent = $this->tideContext->getEventsOfType(TideCreated::class)[0];
        $codeReference = $tideCreatedEvent->getTideContext()->getCodeReference();
        $decoded['repository']['id'] = $codeReference->getRepository()->getIdentifier();
        $decoded['branches'][0]['name'] = $codeReference->getBranch();
        $decoded['branches'][0]['commit']['sha'] = $codeReference->getCommitSha();

        $this->sendWebHook('status', json_encode($decoded));
    }

    /**
     * @When a status webhook is received with the context :context and the value :state for a different code reference
     */
    public function aStatusWebhookIsReceivedWithTheContextAndTheValueForADifferentCodeReference($context, $state)
    {
        $decoded = json_decode(file_get_contents(__DIR__.'/../fixtures/status-pending.json'), true);
        $decoded['context'] = $context;
        $decoded['state'] = $state;

        $this->sendWebHook('status', json_encode($decoded));
    }

    /**
     * @When the branch :branch with head :sha1 is deleted
     */
    public function theBranchIsDeleted($branch, $sha1)
    {
        $contents = \GuzzleHttp\json_decode(file_get_contents(__DIR__.'/../fixtures/push-deleted-pr-branch.json'), true);
        $contents['ref'] = 'refs/head/'.$branch;
        $contents['before'] = $sha1;
        $contents['after'] = CodeReferenceResolver::EMPTY_COMMIT;

        $this->sendWebHook('push', json_encode($contents));
    }

    /**
     * @Then the created tide UUID should be returned
     */
    public function theCreatedTideUuidShouldBeReturned()
    {
        if (200 !== $this->response->getStatusCode()) {
            throw new \RuntimeException(sprintf(
                'Expected status code 200 but got %d',
                $this->response->getStatusCode()
            ));
        }

        if (false === ($json = json_decode($this->response->getContent(), true)) || !isset($json['uuid'])) {
            throw new \RuntimeException('No `uuid` found');
        }
    }

    /**
     * @Given the pull-request #:number contains the tide-related commit
     */
    public function aPullRequestContainsTheTideRelatedCommit($number)
    {
        $this->fakePullRequestResolver->willResolve([
            new \GitHub\WebHook\Model\PullRequest($number, $number),
        ]);
    }

    /**
     * @Then the addresses of the environment should be commented on the pull-request
     */
    public function theAddressesOfTheEnvironmentShouldBeCommentedOnThePullRequest()
    {
        $requests = $this->gitHubHttpClient->getRequests();
        $matchingRequests = array_filter($requests, function(array $request) {
            return $request['method'] == 'POST' && preg_match('#repos/([a-z0-9-]+)/([a-z0-9-]+)/issues/\d+/comments#i', $request['path']);
        });

        if (count($matchingRequests) == 0) {
            throw new \RuntimeException('Expected at least 1 notification, found 0');
        }
    }

    /**
     * @Then the addresses of the environment should not be commented on the pull-request
     */
    public function theAddressesOfTheEnvironmentShouldNotBeCommentedOnThePullRequest()
    {
        $requests = $this->gitHubHttpClient->getRequests();
        $matchingRequests = array_filter($requests, function(array $request) {
            return $request['method'] == 'POST' && preg_match('#repos/([a-z0-9-]+)/([a-z0-9-]+)/issues/\d+/comments#i', $request['path']);
        });

        if (count($matchingRequests) != 0) {
            throw new \RuntimeException(sprintf(
                'Expected at 0 comment, found %d',
                count($matchingRequests)
            ));
        }
    }

    /**
     * @Given a comment identified :commentId was already added
     */
    public function aCommentIdentifiedWasAlreadyAdded($commentId)
    {
        $this->eventStore->add(new CommentedTideFeedback($this->tideContext->getCurrentTideUuid(), $commentId));
    }

    /**
     * @Then the comment :commentId should have been deleted
     */
    public function theCommentShouldHaveBeenDeleted($commentId)
    {
        $requests = $this->gitHubHttpClient->getRequests();
        $matchingRequests = array_filter($requests, function(array $request) use ($commentId) {
            return $request['method'] == 'DELETE' && preg_match('#repos/([a-z0-9-]+)/([a-z0-9-]+)/issues/comments/'.$commentId.'#i', $request['path']);
        });

        if (count($matchingRequests) == 0) {
            throw new \LogicException('Expected at least 1 notification, found 0');
        }
    }

    /**
     * @When a pull-request is created from branch :branch with head commit :sha
     */
    public function aPullRequestIsCreatedWithHeadCommit($branch, $sha)
    {
        $contents = file_get_contents(__DIR__.'/../fixtures/pull_request-created.json');
        $decoded = json_decode($contents, true);
        $decoded['pull_request']['head']['sha'] = $sha;
        $decoded['pull_request']['head']['ref'] = $branch;
        $contents = json_encode($decoded);

        $flowUuid = $this->flowContext->getCurrentUuid();
        $response = $this->kernel->handle(Request::create('/web-hook/github/'.$flowUuid, 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'pull_request',
            'HTTP_X_GITHUB_DELIVERY' => '1234',
        ], $contents));

        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException(sprintf(
                'Expected response code to be bellow 300, got %d',
                $response->getStatusCode()
            ));
        }
    }

    /**
     * @When a pull-request is closed with head commit :sha
     */
    public function aPullRequestIsClosedWithHeadCommit($sha)
    {
        $contents = file_get_contents(__DIR__.'/../fixtures/pull_request-closed.json');
        $decoded = json_decode($contents, true);
        $decoded['pull_request']['head']['sha'] = $sha;
        $contents = json_encode($decoded);

        $flowUuid = $this->flowContext->getCurrentUuid();
        $this->response = $this->kernel->handle(Request::create('/web-hook/github/'.$flowUuid, 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'pull_request',
            'HTTP_X_GITHUB_DELIVERY' => '1234',
        ], $contents));
    }

    /**
     * @Then the environment should be deleted
     */
    public function theEnvironmentShouldBeDeleted()
    {
        $deletions = $this->traceableClient->getDeletions();

        if (0 == count($deletions)) {
            throw new \RuntimeException('No deleted environment found');
        }
    }

    /**
     * @Then the environment should not be deleted
     */
    public function theEnvironmentShouldNotBeDeleted()
    {
        $deletions = $this->traceableClient->getDeletions();

        if (0 != count($deletions)) {
            throw new \RuntimeException('Deleted environment(s) found');
        }
    }

    /**
     * @param string $type
     * @param string $contents
     */
    private function sendWebHook($type, $contents)
    {
        $flowUuid = $this->flowContext->getCurrentUuid();
        $this->response = $this->kernel->handle(Request::create('/web-hook/github/'.$flowUuid, 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $type,
            'HTTP_X_GITHUB_DELIVERY' => '1234',
        ], $contents));

        if (!in_array($this->response->getStatusCode(), [200, 204, 201])) {
            echo $this->response->getContent();
            throw new \RuntimeException(sprintf(
                'Expected status code %d, but got %d',
                200,
                $this->response->getStatusCode()
            ));
        }

        $json = json_decode($this->response->getContent(), true);

        if (isset($json['uuid'])) {
            $uuid = $json['uuid'];
            $this->tideContext->setCurrentTideUuid(\Ramsey\Uuid\Uuid::fromString($uuid));
        }
    }

    /**
     * @return mixed
     */
    private function getLastGitHubNotification()
    {
        $notifications = $this->gitHubTraceableNotifier->getNotifications();
        if (count($notifications) == 0) {
            throw new \RuntimeException('No notification found');
        }

        return $notifications[count($notifications) - 1];
    }
}
