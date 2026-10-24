<?php

declare(strict_types=1);

namespace GroceryRestable\CodeIgniter4\Validation;

use Config\Services;
use GroceryRestable\Validation\ValidatorInterface;

/**
 * Runs setRules() through the CodeIgniter 4 validation service, so every CodeIgniter rule
 * (including is_unique[table.field] and your own rule sets) is available.
 */
final class CodeIgniterValidator implements ValidatorInterface
{
    public function validate(array $data, array $rules): array
    {
        $ciRules = [];
        foreach ($rules as $field => $definition) {
            $isRequired = (bool) preg_match('/(^|\|)required(_with|_without)?(\[|\||$)/', $definition['rules']);
            // Fields that were not sent are only checked by "required" like rules (partial updates).
            if (! array_key_exists($field, $data) && ! $isRequired) {
                continue;
            }
            $ciRules[$field] = ['label' => $definition['label'] ?? $field, 'rules' => $definition['rules']];
        }
        if ($ciRules === []) {
            return [];
        }

        $validation = Services::validation(null, false);
        $validation->setRules($ciRules);
        $validation->run($data);

        return $validation->getErrors();
    }
}
