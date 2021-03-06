<?php

namespace River;

use Behat\Behat\Context\Context;
use Behat\Behat\Tester\Exception\PendingException;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use ContinuousPipe\River\CodeRepository\Branch;
use ContinuousPipe\River\Guzzle\MatchingHandler;
use ContinuousPipe\River\CodeRepository;
use ContinuousPipe\River\CodeRepository\GitHub\CodeReferenceResolver;
use ContinuousPipe\River\CodeRepository\GitHub\GitHubCodeRepository;
use ContinuousPipe\River\Event\GitHub\CommentedTideFeedback;
use ContinuousPipe\River\Event\TideCreated;
use ContinuousPipe\River\EventBus\EventStore;
use ContinuousPipe\River\Notifications\GitHub\CommitStatus\GitHubStateResolver;
use ContinuousPipe\River\Notifications\TraceableNotifier;
use ContinuousPipe\River\Repository\CodeRepositoryRepository;
use ContinuousPipe\River\Tests\CodeRepository\GitHub\TestHttpClient;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use ContinuousPipe\River\Tests\CodeRepository\GitHub\FakePullRequestResolver;
use ContinuousPipe\River\Tests\CodeRepository\InMemoryCodeRepositoryRepository;
use ContinuousPipe\River\Tests\Pipe\TraceableClient;
use GitHub\Integration\InMemoryInstallationRepository;
use GitHub\Integration\InMemoryInstallationTokenResolver;
use GitHub\Integration\Installation;
use GitHub\Integration\InstallationAccount;
use GitHub\Integration\InstallationRepository;
use GitHub\Integration\InstallationToken;
use GitHub\Integration\InstallationTokenResolver;
use GitHub\Integration\TraceableInstallationRepository;
use GitHub\Integration\TraceableInstallationTokenResolver;
use GitHub\WebHook\Model\Repository;
use GuzzleHttp\Exception\ServerException;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class GitHubContext implements CodeRepositoryContext
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
     * @var InMemoryInstallationRepository
     */
    private $inMemoryInstallationRepository;
    /**
     * @var InMemoryInstallationTokenResolver
     */
    private $inMemoryInstallationTokenResolver;
    /**
     * @var InMemoryCodeRepositoryRepository
     */
    private $inMemoryCodeRepositoryRepository;

    /**
     * Used to put in-memory commits before sending them.
     *
     * @var array
     */
    private $commitBuilder = [];
    /**
     * @var MatchingHandler
     */
    private $matchingHandler;

    /**
     * @var string
     */
    private $secret;

    /**
     * @var InstallationTokenResolver
     */
    private $realInstallationTokenResolver;

    /**
     * @var CodeRepositoryRepository
     */
    private $realInstallationRepositoryRepository;

    /**
     * @var TraceableInstallationRepository
     */
    private $traceableInstallationRepository;
    /**
     * @var TraceableInstallationTokenResolver
     */
    private $traceableInstallationTokenResolver;
    /**
     * @var CodeRepository\InMemoryBranchQuery
     */
    private $inMemoryBranchQuery;
    private $repository;

    public function __construct(
        Kernel $kernel,
        TraceableNotifier $gitHubTraceableNotifier,
        FakePullRequestResolver $fakePullRequestResolver,
        TraceableClient $traceableClient,
        EventStore $eventStore,
        TestHttpClient $gitHubHttpClient,
        InMemoryInstallationRepository $inMemoryInstallationRepository,
        TraceableInstallationRepository $traceableInstallationRepository,
        InMemoryInstallationTokenResolver $inMemoryInstallationTokenResolver,
        TraceableInstallationTokenResolver $traceableInstallationTokenResolver,
        InstallationTokenResolver $realInstallationTokenResolver,
        InMemoryCodeRepositoryRepository $inMemoryCodeRepositoryRepository,
        InstallationRepository $realCodeRepositoryRepository,
        MatchingHandler $matchingHandler,
        CodeRepository\InMemoryBranchQuery $inMemoryBranchQuery
    ) {
        $this->kernel = $kernel;
        $this->fakePullRequestResolver = $fakePullRequestResolver;
        $this->traceableClient = $traceableClient;
        $this->eventStore = $eventStore;
        $this->gitHubHttpClient = $gitHubHttpClient;
        $this->gitHubTraceableNotifier = $gitHubTraceableNotifier;
        $this->inMemoryInstallationRepository = $inMemoryInstallationRepository;
        $this->inMemoryInstallationTokenResolver = $inMemoryInstallationTokenResolver;
        $this->inMemoryCodeRepositoryRepository = $inMemoryCodeRepositoryRepository;
        $this->matchingHandler = $matchingHandler;
        $this->secret = $kernel->getContainer()->getParameter('github_secret');
        $this->realInstallationTokenResolver = $realInstallationTokenResolver;
        $this->realInstallationRepositoryRepository = $realCodeRepositoryRepository;
        $this->traceableInstallationRepository = $traceableInstallationRepository;
        $this->traceableInstallationTokenResolver = $traceableInstallationTokenResolver;
        $this->inMemoryBranchQuery = $inMemoryBranchQuery;
    }

    /**
     * @BeforeScenario
     */
    public function gatherContexts(BeforeScenarioScope $scope)
    {
        $this->tideContext = $scope->getEnvironment()->getContext('River\TideContext');
        $this->flowContext = $scope->getEnvironment()->getContext('River\FlowContext');
    }

    /**
     * @Given the URL :url will return :response with the header :headerName valued :headerValue
     */
    public function theUrlWillReturnWithTheHeader($url, $response, $headerName, $headerValue)
    {
        $this->matchingHandler->pushMatcher([
            'match' => function(RequestInterface $request) use ($url, $headerName, $headerValue) {
                return $request->getUri() == $url && $request->getHeaderLine($headerName) == $headerValue;
            },
            'response' => new \GuzzleHttp\Psr7\Response(200, [], $response),
        ]);
    }

    /**
     * @Given the URL :url will return the content of the fixtures file :fixtureFile with the header :headerName valued :headerValue
     */
    public function theUrlWillReturnTheContentOfTheFixturesFileWithTheHeaderValued($url, $fileName, $headerName, $headerValue)
    {
        $this->matchingHandler->pushMatcher([
            'match' => function(RequestInterface $request) use ($url, $headerName, $headerValue) {
                return $request->getUri() == $url && $request->getHeaderLine($headerName) == $headerValue;
            },
            'response' => new \GuzzleHttp\Psr7\Response(200, [], file_get_contents(__DIR__.'/../../river/fixtures/'.$fileName)),
        ]);
    }

    /**
     * @When I request the GitHub installation token for the flow :flowUuid
     */
    public function iRequestTheGithubInstallationTokenForTheFlow($flowUuid)
    {
        $this->response = $this->kernel->handle(Request::create('/github/flows/'.$flowUuid.'/installation-token'));
    }

    /**
     * @Then I should receive the installation token :token
     */
    public function iShouldReceiveTheInstallationToken($token)
    {
        $this->assertResponseStatus(200);

        $json = \GuzzleHttp\json_decode($this->response->getContent(), true);
        if ($token != $json['token']) {
            var_dump($json);

            throw new \RuntimeException('Token not found');
        }
    }

    /**
     * @Given the GitHub account :account have the installation :installationIdentifier
     * @Given the GitHub account :account has the installation :installationIdentifier
     */
    public function theGithubAccountHaveTheInstallation($account, $installationIdentifier)
    {
        $this->inMemoryInstallationRepository->save(new Installation(
            $installationIdentifier,
            new InstallationAccount(
                $installationIdentifier,
                $account
            )
        ));
    }

    /**
     * @Given the token of the GitHub installation :installationIdentifier is :token
     */
    public function theGithubInstallationTokenIs($installationIdentifier, $token)
    {
        $this->inMemoryInstallationTokenResolver->addToken(
            $installationIdentifier,
            new InstallationToken($token, new \DateTime('+1 hour'))
        );
    }

    /**
     * @Given the GitHub repository :identifier exists
     * @Given there is a repository identifier :identifier
     */
    public function thereIsARepositoryIdentified($identifier = null) : CodeRepository
    {
        $repository = GitHubCodeRepository::fromRepository(
            new Repository(
                new \GitHub\WebHook\Model\User('sroze'),
                'docker-php-example',
                'https://api.github.com/repos/sroze/docker-php-example',
                false,
                $identifier ?: 37856553,
                'master'
            )
        );

        $this->inMemoryCodeRepositoryRepository->add($repository);
        $this->repository = $repository;

        return $repository;
    }

    /**
     * @Given the created GitHub comment will have the ID :id
     * @Given the created comment will have the ID :id
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
     * @Given I have a :filePath file in my repository that contains:
     */
    public function iHaveAFileInMyRepositoryThatContains($filePath, PyStringNode $string)
    {
        $this->thereIsAFileContaining($filePath, $string->getRaw());
    }

    /**
     * @Given the code repository will return a :statusCode status code with the following response:
     */
    public function theCodeRepositoryWillReturnAStatusCodeWithTheFollowingResponse($statusCode, PyStringNode $string)
    {
        $contents = $string->getRaw();

        $this->gitHubHttpClient->addHook(function($path, $body, $httpMethod) use ($statusCode, $contents) {
            throw ServerException::create(
                new \GuzzleHttp\Psr7\Request($httpMethod, $path),
                new \GuzzleHttp\Psr7\Response($statusCode, ['Content-Type' => 'application/json'], $contents)
            );
        });
    }

    /**
     * @Given the code repository will return a :statusCode status code for the file :filePath with the following response:
     */
    public function theCodeRepositoryWillReturnAStatusCodeWithTheFollowingResponseForThePath($statusCode, $filePath, PyStringNode $string)
    {
        $contents = $string->getRaw();

        $this->gitHubHttpClient->addHook(function($path, $body, $httpMethod) use ($statusCode, $filePath, $contents) {
            if (preg_match('#repos/([^/]+)/([^/]+)/contents/'.$filePath.'$#', $path)) {
                throw ServerException::create(
                    new \GuzzleHttp\Psr7\Request($httpMethod, $path),
                    new \GuzzleHttp\Psr7\Response($statusCode, ['Content-Type' => 'application/json'], $contents)
                );
            }
        });
    }

    public function thereIsAFileContaining(string $filePath, string $contents)
    {
        $this->gitHubHttpClient->addHook(function($path, $body, $httpMethod) use ($filePath, $contents) {
            if (in_array($httpMethod, ['HEAD', 'GET']) && preg_match('#repos/([^/]+)/([^/]+)/contents/'.$filePath.'$#', $path)) {
                return new \Guzzle\Http\Message\Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode(['content' => base64_encode($contents)])
                );
            }
        });
    }


    /**
     * @Given the changes between the reference :base and :head are:
     */
    public function theChangesBetweenTheReferenceAndAre($base, $head, TableNode $table)
    {
        $compareBranches = \GuzzleHttp\json_decode($this->readFixture('compare-branches.json'), true);
        $compareBranches['files'] = [];

        foreach ($table->getHash() as $row) {
            $compareBranches['files'][] = [
                'filename' => $row['filename'],
                'status' => $row['status'],
            ];
        }

        $this->gitHubHttpClient->addHook(function($path, $body, $httpMethod) use ($base, $head, $compareBranches) {
            if (in_array($httpMethod, ['GET']) && preg_match('#repos/([^/]+)/([^/]+)/compare/'.$base.'...'.$head.'$#', $path)) {
                return new \Guzzle\Http\Message\Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode($compareBranches)
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
     * @When the GitHub commit :sha is pushed to the branch :branch
     */
    public function theCommitIsPushedToTheBranch($sha, $branch)
    {
        $contents = \GuzzleHttp\json_decode($this->readFixture('push-master.json'), true);
        $contents['ref'] = 'refs/heads/'.$branch;
        $contents['after'] = $sha;
        $contents['head_commit']['id'] = $sha;

        $this->sendWebHook('push', json_encode($contents));
    }

    /**
     * @When the commit :sha is pushed to the branch :branch by the user :user with an email :email
     */
    public function theCommitIsPushedToTheBranchByTheUserWithAnEmail($sha, $branch, $user, $email)
    {
        $contents = \GuzzleHttp\json_decode($this->readFixture('push-master.json'), true);
        $contents['ref'] = 'refs/heads/'.$branch;
        $contents['after'] = $sha;
        $contents['head_commit']['id'] = $sha;
        $contents['commits'][0]['id'] = $sha;
        $contents['commits'][0]['author']['username'] = $user;
        $contents['commits'][0]['author']['name'] = $user;
        $contents['commits'][0]['author']['email'] = $email;
        $contents['commits'][0]['committer']['username'] = $user;
        $contents['commits'][0]['committer']['name'] = $user;
        $contents['commits'][0]['committer']['email'] = $email;

        $this->sendWebHook('push', json_encode($contents));
    }

    /**
     * @Given the commit :sha1 has been written by the user :username with an email :email
     */
    public function theCommitHasBeenWrittenByTheUserWithAnEmail($sha1, $username, $email)
    {
        $this->commitBuilder[$sha1] = ['username' => $username, 'name' => $username, 'email' => $email];
    }

    /**
     * @When the commits :commits are pushed to the branch :branch
     */
    public function theCommitsArePushedToTheBranch($commits, $branch)
    {
        $contents = \GuzzleHttp\json_decode($this->readFixture('push-master.json'), true);
        $contents['ref'] = 'refs/heads/'.$branch;

        $commitTemplate = $contents['commits'][0];
        $contents['commits'] = [];

        foreach (explode(',', $commits) as $index => $sha) {
            $commitAuthor = $this->commitBuilder[$sha];

            $contents['commits'][$index] = $commitTemplate;
            $contents['commits'][$index]['id'] = $sha;
            $contents['commits'][$index]['author'] = $commitAuthor;
            $contents['commits'][$index]['committer'] = $commitAuthor;
        }

        $contents['after'] = $sha;
        $contents['head_commit']['id'] = $sha;

        $this->sendWebHook('push', json_encode($contents));
    }

    /**
     * @When the pull request #:number is opened
     */
    public function thePullRequestIsOpened($number)
    {
        $contents = \GuzzleHttp\json_decode($this->readFixture('pull_request-created.json'), true);
        $contents['number'] = $number;

        $this->sendWebHook('pull_request', json_encode($contents));
    }

    /**
     * @When the pull request #:number is opened with head :branch and the commit :sha
     */
    public function thePullRequestIsOpenedWithHeadAndTheCommit($number, $branch, $sha)
    {
        $contents = \GuzzleHttp\json_decode($this->readFixture('pull_request-created.json'), true);
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
        $contents = \GuzzleHttp\json_decode($this->readFixture('pull_request-created.json'), true);

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
        $contents = \GuzzleHttp\json_decode($this->readFixture('pull_request-created.json'), true);
        $contents['action'] = 'synchronize';
        $contents['number'] = $number;
        $contents['pull_request']['head']['ref'] = $branch;
        $contents['pull_request']['head']['label'] = $branch;
        $contents['pull_request']['head']['sha'] = $sha;

        $this->sendWebHook('pull_request', json_encode($contents));
    }

    /**
     * @When the pull request #:number is synchronized
     * @When the pull request #:number for branch :headRef is synchronized
     */
    public function thePullRequestIsSynchronized($number, $headRef = null)
    {
        $contents = \GuzzleHttp\json_decode($this->readFixture('pull_request-created.json'), true);
        $contents['number'] = $number;
        $contents['action'] = 'synchronize';

        if ($headRef !== null) {
            $contents['pull_request']['head']['ref'] = $headRef;
        }

        $this->sendWebHook('pull_request', json_encode($contents));
    }

    /**
     * @When the pull request #:number is labeled
     * @When the pull request #:number for branch :headRef is labeled
     */
    public function thePullRequestIsLabeled($number, $headRef = null)
    {
        $contents = \GuzzleHttp\json_decode($this->readFixture('pull_request-created.json'), true);
        $contents['number'] = $number;
        $contents['action'] = 'labeled';

        if ($headRef !== null) {
            $contents['pull_request']['head']['ref'] = $headRef;
        }

        $this->sendWebHook('pull_request', json_encode($contents));
    }

    /**
     * @When the pull request #:number is unlabeled
     */
    public function thePullRequestIsUnlabeled($number)
    {
        $contents = \GuzzleHttp\json_decode($this->readFixture('pull_request-created.json'), true);
        $contents['number'] = $number;
        $contents['action'] = 'unlabeled';

        $this->sendWebHook('pull_request', json_encode($contents));
    }

    /**
     * @When the pull request #:number is labeled with head :branch and the commit :sha
     */
    public function thePullRequestIsLabeledWithHeadAndTheCommand($number, $branch, $sha)
    {
        $contents = \GuzzleHttp\json_decode($this->readFixture('pull_request-created.json'), true);
        $contents['number'] = $number;
        $contents['action'] = 'labeled';
        $contents['pull_request']['head']['ref'] = $branch;
        $contents['pull_request']['head']['label'] = $branch;
        $contents['pull_request']['head']['sha'] = $sha;

        $this->sendWebHook('pull_request', json_encode($contents));
    }

    /**
     * @Given the pull request #:number have the label :label
     * @Given the pull request #:number for branch :branch have the label :label
     */
    public function thePullRequestHaveTheLabel($number, $label, $branch = null)
    {
        $this->thePullRequestHaveTheLabels($number, $label, $branch);
    }

    /**
     * @Given the pull request #:number have the labels :labelsString
     * @Given the pull request #:number for branch :branch have the labels :labelsString
     */
    public function thePullRequestHaveTheLabels($number, $labelsString, $branch = null)
    {
        $this->gitHubHttpClient->addHook(function($path, $body, $httpMethod, $headers) use ($number, $labelsString, $branch) {
            if ($httpMethod == 'GET' && preg_match('#/pulls$#', $path)) {
                return new \Guzzle\Http\Message\Response(
                    200,
                    [
                        'Content-Type' => 'application/json',
                    ],
                    json_encode([
                        [
                            'id' => $number,
                            'number' => $number,
                            'head' => [
                                'ref' => $branch,
                            ]
                        ],
                    ])
                );
            }


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
        $this->sendWebHook('push', $this->readFixture('push-master.json'));
    }

    /**
     * @When a status webhook is received with the context :context and the value :state
     */
    public function aStatusWebhookIsReceivedWithTheContextAndTheValue($context, $state)
    {
        $decoded = json_decode($this->readFixture('status-pending.json'), true);
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
        $decoded = json_decode($this->readFixture('status-pending.json'), true);
        $decoded['context'] = $context;
        $decoded['state'] = $state;

        try {
            $this->sendWebHook('status', json_encode($decoded));
        } catch (\RuntimeException $e) {}
    }

    /**
     * @When the branch :branch with head :sha1 is deleted
     */
    public function theBranchIsDeleted($branch, $sha1)
    {
        $contents = \GuzzleHttp\json_decode($this->readFixture('push-deleted-pr-branch.json'), true);
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
     * @Given the GitHub pull-request #:number contains the tide-related commit
     * @Given the GitHub pull-request #:number titled :title contains the tide-related commit
     * @Given there is a GitHub pull-request #:number titled :title for branch :branch
     */
    public function aPullRequestContainsTheTideRelatedCommit($number, $title = null, $branch = null)
    {
        $this->fakePullRequestResolver->willResolve([
            CodeRepository\PullRequest::github($number, $this->repository->getAddress(), $title, isset($branch) ? new Branch($branch): null),
        ]);
    }

    /**
     * @Given the pull-request #:number do not contains the tide-related commit
     */
    public function thePullRequestDoNotContainsTheTideRelatedCommit()
    {
        $this->fakePullRequestResolver->willResolve([]);
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
            throw new \RuntimeException(sprintf('Expected 0 notification, found %d', count($matchingRequests)));
        }
    }

    /**
     * @Then the address :address should be commented on the pull-request
     */
    public function theAddressShouldBeCommentedOnThePullRequest($address)
    {
        $requests = $this->gitHubHttpClient->getRequests();
        $matchingRequests = array_filter($requests, function(array $request) {
            return $request['method'] == 'POST' && preg_match('#repos/([a-z0-9-]+)/([a-z0-9-]+)/issues/\d+/comments#i', $request['path']);
        });

        $matchingComments = array_filter($matchingRequests, function(array $request) use ($address) {
            $comment = \GuzzleHttp\json_decode($request['body'], true)['body'];

            return strpos($comment, $address) !== false;
        });

        if (count($matchingComments) == 0) {
            throw new \RuntimeException('No comment containing this address found');
        }
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
     * @When a pull-request is created with good signature
     */
    public function aPullRequestIsCreatedWithGoodSignature($validSignature = true)
    {
        $contents = $this->readFixture('pull_request-closed.json');
        $decoded = json_decode($contents, true);
        $contents = json_encode($decoded);
        $hashAlgorithm = 'sha1';
        $secret = $validSignature ? $this->secret : $this->secret . '_invalid';

        $flowUuid = $this->flowContext->getCurrentUuid();
        $request = Request::create(
                '/web-hook/github/' . $flowUuid, 'POST', [], [], [], [
                    'CONTENT_TYPE'           => 'application/json',
                    'HTTP_X_GITHUB_EVENT'    => 'pull_request',
                    'HTTP_X_GITHUB_DELIVERY' => '1234',
                ], $contents
            );
        $hash = hash_hmac($hashAlgorithm, $contents, $secret);
        $request->headers->set('X-Hub-Signature', sprintf('%s=%s', $hashAlgorithm, $hash));
        $this->response = $this->kernel->handle($request);
    }

    /**
     * @When a pull-request is created with invalid signature
     */
    public function aPullRequestIsCreatedWithInvalidSignature()
    {
        $this->aPullRequestIsCreatedWithGoodSignature(false);
    }

    /**
     * @When a pull-request is created from branch :branch with head commit :sha
     */
    public function aPullRequestIsCreatedWithHeadCommit($branch, $sha)
    {
        $this->fakePullRequestResolver->willResolve([
            new CodeRepository\PullRequest(1),
        ]);

        $contents = $this->readFixture('pull_request-created.json');
        $decoded = json_decode($contents, true);
        $decoded['pull_request']['head']['sha'] = $sha;
        $decoded['pull_request']['head']['ref'] = $branch;
        $contents = json_encode($decoded);
        $hashAlgorithm = 'sha1';

        $flowUuid = $this->flowContext->getCurrentUuid();
        $request = Request::create('/web-hook/github/'.$flowUuid, 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'pull_request',
            'HTTP_X_GITHUB_DELIVERY' => '1234',
        ], $contents);
        $hash = hash_hmac($hashAlgorithm, $contents, $this->secret);
        $request->headers->set('X-Hub-Signature', sprintf('%s=%s', $hashAlgorithm, $hash));
        $response = $this->kernel->handle($request);

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
        $contents = $this->readFixture('pull_request-closed.json');
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
     * @Then a commit status should have been sent
     */
    public function aCommitStatusShouldHaveBeenSent()
    {
        $this->getLastGitHubNotification();
    }

    /**
     * @Then processing the webhook should be denied
     * @Then I should not be allowed to see the installation token
     */
    public function iShouldDenyTheAccessToTheWebhook()
    {
        $this->assertResponseStatus(403);
    }

    /**
     * @Then I should be told I need be authenticated
     */
    public function iShouldBeToldINeedToBeAuthenticated()
    {
        $this->assertResponseStatus(401);
    }

    /**
     * @Then processing the webhook should be successfully completed
     */
    public function processingTheWebhookShouldBeSuccessfullyCompleted()
    {
        $this->assertResponseStatus(204);
    }

    /**
     * @Then processing the webhook should be accepted
     */
    public function processingTheWebhookShouldBeAccepted()
    {
        $this->assertResponseStatus(202);
    }

    /**
     * @When a push webhook is received with good signature
     */
    public function aPushWebhookIsReceivedWithGoodSignature()
    {
        $contents = \GuzzleHttp\json_decode($this->readFixture('push-master.json'), true);

        $this->sendWebHook('push', json_encode($contents));
    }

    /**
     * @When a push webhook is received with invalid signature
     */
    public function aPushWebhookIsReceivedWithInvalidSignature()
    {
        $contents = \GuzzleHttp\json_decode($this->readFixture('push-master.json'), true);

        $this->sendWebHookWithInvalidSignature('push', json_encode($contents));
    }

    /**
     * @When I look up the token for installation :installationIdentifier :count times
     * @Given the token for installation :installationIdentifier is retrieved once
     */
    public function iLookUpTheTokenForInstallationTimes($installationIdentifier, $count = 1)
    {
        $installations = array_filter(
            $this->inMemoryInstallationRepository->findAll(),
            function(Installation $installation) use($installationIdentifier) {
                return $installationIdentifier == $installation->getId();
            }
        );

        if (count($installations) === 0) {
            throw new \RuntimeException(
                sprintf('No installation with ID "%s" found in repository.', $installationIdentifier)
            );
        }

        $installation = reset($installations);

        for ($i = 1; $i <= $count; $i++) {
            $this->realInstallationTokenResolver->get($installation);
        }
    }

    /**
     * @Then GitHub access token API should have been called once
     *
     */
    public function gitHubAPIShouldBeCalledOnce()
    {
        $this->gitHubAPIShouldBeCalledTimes(1);
    }

    /**
     * @Then GitHub access token API should have been called twice
     */
    public function gitHubAPIShouldBeCalledTwice()
    {
        $this->gitHubAPIShouldBeCalledTimes(2);
    }

    /**
     * @When I look up the installation for repository :identifier :count times
     * @Given the installation for repository :identifier is retrieved once
     */
    public function iLookUpTheInstallationForRepositoryTimes($identifier, $count = 1)
    {
        $repository = $this->inMemoryCodeRepositoryRepository->findByIdentifier($identifier);

        for ($i = 1; $i <= $count; $i++) {
            $this->realInstallationRepositoryRepository->findByRepository($repository);
        }
    }

    /**
     * @Then GitHub repository API should have been called once
     */
    public function gitHubRepositoryAPIShouldHaveBeenCalledOnce()
    {
        $this->gitHubRepositoryAPIShouldHaveBeenCalledTimes(1);
    }

    /**
     * @Then GitHub repository API should have been called twice
     */
    public function gitHubRepositoryAPIShouldHaveBeenCalledTwice()
    {
        $this->gitHubRepositoryAPIShouldHaveBeenCalledTimes(2);
    }

    /**
     * @When GitHub installation :installationIdentifier is deleted
     */
    public function gitHubInstallationIsDeleted($installationIdentifier)
    {
        $contents = \GuzzleHttp\json_decode($this->readFixture('integration_installation-deleted.json'), true);

        $contents['installation']['id'] = $installationIdentifier;

        $this->sendWebHook('integration_installation', json_encode($contents));
    }

    /**
     * @When a GitHub repository is added to the installation :installationIdentifier
     */
    public function aGithubRepositoryIsAddedToTheIntegration($installationIdentifier)
    {
        $contents = \GuzzleHttp\json_decode($this->readFixture('integration_repository-added.json'), true);

        $contents['installation']['id'] = $installationIdentifier;

        $this->sendWebHook('installation_repositories', json_encode($contents));
    }

    /**
     * @When a GitHub repository is removed from the installation :installationIdentifier
     */
    public function aGithubRepositoryIsRemovedFromTheIntegration($installationIdentifier)
    {
        $contents = \GuzzleHttp\json_decode($this->readFixture('integration_repository-removed.json'), true);

        $contents['installation']['id'] = $installationIdentifier;

        $this->sendWebHook('installation_repositories', json_encode($contents));
    }

    /**
     * @Given the following branches exists in the github repository:
     */
    public function theFollowingBranchesExistsInTheGithubRepository(TableNode $table)
    {
        $url = sprintf(
            'https://api.github.com/repos/%s/%s/branches',
            'sroze',
            'docker-php-example'
        );

        $branches = array_map(function(array $b) {
            $branch =  [
                'name' => $b['name'],
            ];
            if (isset($b['sha']) && isset($b['commit-url'])) {
                $branch['commit'] = [
                    'sha' => $b['sha'],
                    'url' => $b['commit-url'],
                ];
            }

            if (isset($b['datetime'])) {
                $branch['commit']['timestamp'] = $b['datetime'];
            }

            return $branch;
        }, $table->getHash());

        $this->matchingHandler->pushMatcher([
            'match' => function(RequestInterface $request) use ($url) {
                return $request->getUri() == $url;
            },
            'response' => new \GuzzleHttp\Psr7\Response(200, [], \GuzzleHttp\json_encode($branches)),
        ]);
        
        $this->inMemoryBranchQuery->notOnlyInMemory();
    }

    /**
     * @Given the following branches exists in the github repository and are paginated in the api response:
     */
    public function theFollowingBranchesExistsInTheGithubRepositoryAndArePaginatedInTheApiResponse(TableNode $table)
    {
        $url = sprintf(
            'https://api.github.com/repos/%s/%s/branches',
            'sroze',
            'docker-php-example'
        );

        $this->matchingHandler->pushMatcher([
            'match' => function(RequestInterface $request) use ($url) {
                return $request->getUri() == $url;
            },
            'response' => new \GuzzleHttp\Psr7\Response(200, ['Link' =>  '<'.$url.'?page=2>; rel="next"'], \GuzzleHttp\json_encode([$table->getHash()[0]])),
        ]);

        $this->matchingHandler->pushMatcher([
            'match' => function(RequestInterface $request) use ($url) {
                return $request->getUri() == $url.'?page=2';
            },
            'response' => new \GuzzleHttp\Psr7\Response(200, [], \GuzzleHttp\json_encode(array_slice($table->getHash(), 1))),
        ]);

        $this->inMemoryBranchQuery->notOnlyInMemory();
    }

    /**
     * @param string $type
     * @param string $contents
     */
    private function sendWebHook($type, $contents)
    {
        $hashAlgorithm = 'sha1';
        $hash = hash_hmac($hashAlgorithm, $contents, $this->secret);

        $request = Request::create(
                '/github/integration/webhook', 'POST', [], [], [], [
                'CONTENT_TYPE'           => 'application/json',
                'HTTP_X_GITHUB_EVENT'    => $type,
                'HTTP_X_GITHUB_DELIVERY' => '1234',
            ], $contents
        );
        $request->headers->set('X-Hub-Signature', sprintf('%s=%s', $hashAlgorithm, $hash));
        $this->response = $this->kernel->handle($request);

        if (!in_array($this->response->getStatusCode(), [200, 202, 204, 201])) {
            echo $this->response->getContent();
            throw new \RuntimeException(sprintf(
                'Unexpected status code %d',
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
     * @param $type
     * @param $contents
     */
    private function sendWebHookWithInvalidSignature($type, $contents)
    {
        $hashAlgorithm = 'sha1';
        $secret = $this->secret . '_invalid';
        $hash = hash_hmac($hashAlgorithm, $contents, $secret);

        $request = Request::create(
            '/github/integration/webhook', 'POST', [], [], [], [
            'CONTENT_TYPE'           => 'application/json',
            'HTTP_X_GITHUB_EVENT'    => $type,
            'HTTP_X_GITHUB_DELIVERY' => '1234',
        ], $contents
        );
        $request->headers->set('X-Hub-Signature', sprintf('%s=%s', $hashAlgorithm, $hash));
        $this->response = $this->kernel->handle($request);
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

    private function readFixture(string $fixture) : string
    {
        return file_get_contents(__DIR__.'/../../river/integrations/code-repositories/github/fixtures/'.$fixture);
    }

    /**
     * @param int $expectedStatus
     */
    private function assertResponseStatus(int $expectedStatus)
    {
        if ($this->response->getStatusCode() != $expectedStatus) {
            echo $this->response->getContent();

            throw new \RuntimeException(sprintf(
                'Expected status code %d, found %d',
                $expectedStatus,
                $this->response->getStatusCode()
            ));
        }
    }

    private function gitHubAPIShouldBeCalledTimes($count)
    {
        if ($count != $this->traceableInstallationTokenResolver->countApiCalls()) {
            throw new \UnexpectedValueException(
                sprintf(
                    'GitHub access token API expected to be called %d time(s), but called %d time(s).',
                    $count,
                    $this->traceableInstallationTokenResolver->countApiCalls()
                )
            );
        }
    }

    private function gitHubRepositoryAPIShouldHaveBeenCalledTimes($count)
    {
        if ($count != $this->traceableInstallationRepository->countApiCalls()) {
            throw new \UnexpectedValueException(
                sprintf(
                    'GitHub repository API expected to be called %d time(s), but called %d time(s).',
                    $count,
                    $this->traceableInstallationRepository->countApiCalls()
                )
            );
        }
    }
}
