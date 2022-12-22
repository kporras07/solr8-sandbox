<?php

namespace Kporras07\ComposerDisablePlugin\Rules;

class IsGithubActionsRule extends RuleEnvBase
{
    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->name = 'isGithubActions';
        $this->envName = 'GITHUB_ACTIONS';
    }
}
