<?php

namespace App\Services\Workflow\Conditions;

use App\Models\Ticket;

/**
 * Evaluates workflow trigger conditions against a ticket.
 *
 * Condition format (stored in workflow.trigger.conditions):
 * [
 *   { "field": "priority", "operator": "equals", "value": "urgent" },
 *   { "field": "status",   "operator": "in",     "value": ["open", "in_progress"] },
 *   { "field": "subject",  "operator": "contains","value": "billing" }
 * ]
 *
 * All conditions in the array must pass (AND logic).
 * For OR logic, create separate workflows.
 */
class ConditionEvaluator
{
    private const TICKET_FIELDS = ['status', 'priority', 'channel', 'subject', 'description'];

    public function evaluate(Ticket $ticket, array $conditions): bool
    {
        foreach ($conditions as $condition) {
            if (!$this->evaluateCondition($ticket, $condition)) {
                return false;
            }
        }
        return true;
    }

    private function evaluateCondition(Ticket $ticket, array $condition): bool
    {
        $field    = $condition['field']    ?? '';
        $operator = $condition['operator'] ?? 'equals';
        $value    = $condition['value']    ?? null;

        if (!in_array($field, self::TICKET_FIELDS, true)) {
            return true; // Unknown fields don't block execution
        }

        $ticketValue = $ticket->{$field} ?? '';

        return match ($operator) {
            'equals'      => $ticketValue === $value,
            'not_equals'  => $ticketValue !== $value,
            'contains'    => str_contains(strtolower((string) $ticketValue), strtolower((string) $value)),
            'starts_with' => str_starts_with(strtolower((string) $ticketValue), strtolower((string) $value)),
            'in'          => is_array($value) && in_array($ticketValue, $value, true),
            'not_in'      => is_array($value) && !in_array($ticketValue, $value, true),
            default       => true,
        };
    }
}
