<?php

namespace Kporras07\ComposerDisablePlugin\Rules;

class CodeEvaluatesTrue extends RuleBase
{
    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->name = 'codeEvaluatesTrue';
    }

    /**
     * {@inheritdoc}
     */
    public function evaluate(array $config = []): bool
    {
        $code = $config['code'] ?? '';
        return eval($code) === true;
    }
}
