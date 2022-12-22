<?php

namespace Kporras07\ComposerDisablePlugin\Rules;

interface RuleInterface
{
    /**
     * Evaluate current rule.
     */
    public function evaluate(array $config = []): bool;

    /**
     * Get name.
     */
    public function getName(): string;
}
