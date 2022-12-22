<?php

namespace Kporras07\ComposerDisablePlugin\Rules;

class CodeEvaluatesFalse extends RuleBase
{
    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->name = 'codeEvaluatesFalse';
    }

    /**
     * {@inheritdoc}
     */
    public function evaluate(array $config = []): bool
    {
        $code = $config['code'] ?? '';
        return eval($code) === false;
    }
}
