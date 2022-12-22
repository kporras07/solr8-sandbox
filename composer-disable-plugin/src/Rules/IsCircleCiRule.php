<?php

namespace Kporras07\ComposerDisablePlugin\Rules;

class IsCircleCiRule extends RuleEnvBase
{
    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->name = 'isCircleCi';
        $this->envName = 'CIRCLECI';
    }
}
