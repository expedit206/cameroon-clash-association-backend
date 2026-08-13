<?php

namespace App\Services\ClashBet;

class RuleNegatorService
{
    /**
     * Inverse une règle AST JSON de façon déterministe (Lois de De Morgan).
     */
    public function negate(array $rule): array
    {
        $type = $rule['type'] ?? 'comparison';

        if ($type === 'logical') {
            $operator = strtoupper($rule['operator'] ?? 'AND');
            $negatedOperator = ($operator === 'AND') ? 'OR' : 'AND';

            $negatedChildren = array_map(function ($child) {
                return $this->negate($child);
            }, $rule['children'] ?? []);

            return [
                'type'     => 'logical',
                'operator' => $negatedOperator,
                'children' => $negatedChildren,
            ];
        }

        if ($type === 'comparison_entities') {
            $op = $rule['operator'] ?? '=';
            return array_merge($rule, [
                'operator' => $this->negateOperator($op),
            ]);
        }

        // Standard comparison
        $op = $rule['operator'] ?? '=';
        return array_merge($rule, [
            'operator' => $this->negateOperator($op),
        ]);
    }

    /**
     * Inversion stricte des opérateurs de comparaison.
     */
    private function negateOperator(string $op): string
    {
        return match ($op) {
            '>='    => '<',
            '>'     => '<=',
            '='     => '!=',
            '!='    => '=',
            '<='    => '>',
            '<'     => '>=',
            default => '!=',
        };
    }
}
