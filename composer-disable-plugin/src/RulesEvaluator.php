<?php

namespace Kporras07\ComposerDisablePlugin;

class RulesEvaluator
{
    /**
     * @var array
     */
    protected $rules;

    public function __construct()
    {
        $dir = __DIR__ . '/Rules';
        $files = scandir($dir);
        foreach ($files as $file) {
            if (substr($file, -8) === 'Rule.php') {
                $class = 'Kporras07\\ComposerDisablePlugin\\Rules\\' . substr($file, 0, -4);
                $rule = new $class();
                $this->rules[$rule->getName()] = $rule;
            }
        }
        // @todo Load rules from additional plugins/files.
    }

    /**
     * Evaluate rules.
     */
    public function evaluate(array $rules = [], string $rulesConjunction = 'or'): bool
    {
        $result = true;
        foreach ($rules as $rule) {
            $ruleName = $rule['name'];
            $ruleConfig = $rule['config'] ?? [];
            $ruleResult = $this->rules[$ruleName]->evaluate($ruleConfig);
            if (strtolower($rulesConjunction) === 'and') {
                $result = $result && $ruleResult;
            } else {
                $result = $result || $ruleResult;
            }
        }

        return $result;
    }
}
