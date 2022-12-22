<?php

namespace Kporras07\ComposerDisablePlugin\Rules;

abstract class RuleBase implements RuleInterface
{
    protected $name;

    /**
     * {@inheritdoc}
     */
    abstract public function evaluate(array $config = []): bool;

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return $this->name;
    }
}
