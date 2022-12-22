<?php

namespace Kporras07\ComposerDisablePlugin\Rules;

class AlwaysTrueRule extends RuleBase
{
    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->name = 'alwaysTrue';
    }

    /**
     * {@inheritdoc}
     */
    public function evaluate(array $config = []): bool
    {
        return true;
    }
}
