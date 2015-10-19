<?php

namespace ContinuousPipe\River\Flow;

use ContinuousPipe\River\CodeReference;
use ContinuousPipe\River\CodeRepository\FileSystemResolver;
use ContinuousPipe\River\Flow;
use ContinuousPipe\River\Task\TaskFactoryRegistry;
use ContinuousPipe\River\TideConfigurationException;
use ContinuousPipe\River\TideConfigurationFactory;
use ContinuousPipe\Security\Credentials\BucketRepository;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Yaml\Yaml;

class ConfigurationFactory implements TideConfigurationFactory
{
    /**
     * @var FileSystemResolver
     */
    private $fileSystemResolver;

    /**
     * @var TaskFactoryRegistry
     */
    private $taskFactoryRegistry;

    /**
     * @var ConfigurationEnhancer[]
     */
    private $configurationEnhancers;

    /**
     * @param FileSystemResolver      $fileSystemResolver
     * @param TaskFactoryRegistry     $taskFactoryRegistry
     * @param ConfigurationEnhancer[] $configurationEnhancers
     */
    public function __construct(FileSystemResolver $fileSystemResolver, TaskFactoryRegistry $taskFactoryRegistry, array $configurationEnhancers)
    {
        $this->fileSystemResolver = $fileSystemResolver;
        $this->taskFactoryRegistry = $taskFactoryRegistry;
        $this->configurationEnhancers = $configurationEnhancers;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfiguration(Flow $flow, CodeReference $codeReference)
    {
        $flowContext = $flow->getContext();
        $fileSystem = $this->fileSystemResolver->getFileSystem($codeReference, $flowContext->getTeam());

        $configs = [
            $flowContext->getConfiguration(),
        ];

        // Read configuration from YML
        if ($fileSystem->exists(self::FILENAME)) {
            $configs[] = Yaml::parse($fileSystem->getContents(self::FILENAME));
        }

        // Enhance configuration
        foreach ($this->configurationEnhancers as $enhancer) {
            $configs = $enhancer->enhance($flow, $codeReference, $configs);
        }

        $configurationDefinition = new Configuration($this->taskFactoryRegistry);
        $processor = new Processor();

        try {
            $configuration = $processor->processConfiguration($configurationDefinition, $configs);
        } catch (InvalidConfigurationException $e) {
            throw new TideConfigurationException($e->getMessage(), 0, $e);
        }

        return $configuration;
    }
}
