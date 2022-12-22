<?php

namespace Kporras07\ComposerDisablePlugin\Rules;

abstract class RuleEnvBase extends RuleBase
{
    protected $name;
    protected $envName;

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function evaluate(array $config = []): bool
    {
        return isset($_SERVER[$this->envName]) || isset($_ENV[$this->envName]);
    }
}
