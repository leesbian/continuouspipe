<?php

namespace ContinuousPipe\River\Flow\ConfigurationFinalizer;

use ContinuousPipe\River\CodeReference;
use ContinuousPipe\River\Flow\ConfigurationFinalizer;
use ContinuousPipe\River\Flow\Projections\FlatFlow;
use ContinuousPipe\River\Tide\Configuration\ArrayObject;
use ContinuousPipe\River\TideConfigurationException;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\SyntaxError;

class ReplaceEnvironmentVariableValues implements ConfigurationFinalizer
{
    /**
     * {@inheritdoc}
     */
    public function finalize(FlatFlow $flow, CodeReference $codeReference, array $configuration)
    {
        // Replace the pipeline variables first
        foreach ($configuration['pipelines'] as &$pipeline) {
            $variables = $this->resolveVariables($pipeline, $this->createContext($flow, $codeReference));
            $pipeline = self::replaceValues($pipeline, $variables);
        }

        // Replace the tasks variables
        $variables = $this->resolveVariables($configuration, $this->createContext($flow, $codeReference));
        $configuration = self::replaceValues($configuration, $variables);

        return $configuration;
    }

    /**
     * @param array       $configuration
     * @param ArrayObject $context
     *
     * @return array
     */
    private function resolveVariables(array $configuration, ArrayObject $context)
    {
        if (!isset($configuration['variables'])) {
            return [];
        }

        $variables = [];
        foreach ($configuration['variables'] as $item) {
            if (array_key_exists('condition', $item) && !$this->isConditionValid($item['condition'], $context)) {
                continue;
            }

            if (array_key_exists('expression', $item)) {
                $item['value'] = $this->resolveExpression($item['expression'], $context);
            }

            $variables[$item['name']] = $item['value'];
        }

        return $variables;
    }

    /**
     * @param array $array
     * @param array $mapping
     *
     * @return array
     */
    public static function replaceValues(array $array, array $mapping)
    {
        array_walk_recursive($array, function (&$value) use ($mapping) {
            if (is_string($value)) {
                $value = self::replaceVariables($value, $mapping);
            }
        });

        return $array;
    }

    /**
     * @param string $value
     * @param array  $mapping
     *
     * @return string
     */
    public static function replaceVariables(string $value, array $mapping)
    {
        $variableKeys = array_map(function ($key) {
            return sprintf('${%s}', $key);
        }, array_keys($mapping));

        return str_replace($variableKeys, array_values($mapping), $value);
    }

    /**
     * @param FlatFlow      $flow
     * @param CodeReference $codeReference
     *
     * @return ArrayObject
     */
    private function createContext(FlatFlow $flow, CodeReference $codeReference)
    {
        return new ArrayObject([
            'code_reference' => new ArrayObject([
                'branch' => $codeReference->getBranch(),
                'sha' => $codeReference->getCommitSha(),
            ]),
            'flow' => new ArrayObject([
                'uuid' => (string) $flow->getUuid(),
            ]),
        ]);
    }

    /**
     * @param string      $condition
     * @param ArrayObject $context
     *
     * @return bool
     *
     * @throws TideConfigurationException
     */
    private function isConditionValid($condition, ArrayObject $context)
    {
        return (bool) $this->resolveExpression($condition, $context);
    }

    /**
     * @param string      $expression
     * @param ArrayObject $context
     *
     * @return string
     *
     * @throws TideConfigurationException
     */
    private function resolveExpression($expression, ArrayObject $context)
    {
        $language = new ExpressionLanguage();

        try {
            return $language->evaluate($expression, $context->asArray());
        } catch (SyntaxError $e) {
            throw new TideConfigurationException(sprintf(
                'The expression provided ("%s") is not valid: %s',
                $expression,
                $e->getMessage()
            ), $e->getCode(), $e);
        } catch (\InvalidArgumentException $e) {
            throw new TideConfigurationException($e->getMessage(), $e->getCode(), $e);
        }
    }
}
