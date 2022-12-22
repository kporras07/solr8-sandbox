<?php

namespace Kporras07\ComposerDisablePlugin\Rules;

class IsCiRule extends RuleEnvBase
{
    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->name = 'isCi';
        $this->envName = 'CI';
    }
}
