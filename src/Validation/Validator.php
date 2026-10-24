<?php

declare(strict_types=1);

namespace GroceryRestable\Validation;

use DateTimeImmutable;
use GroceryRestable\Exception\ConfigurationException;

/**
 * Built-in validator implementing the most used CodeIgniter 4 rules.
 * The CodeIgniter 4 integration replaces it with the framework validation service.
 */
final class Validator implements ValidatorInterface
{
    /** @var array<string, callable(mixed, ?string, array<string, mixed>): bool> */
    private array $custom = [];

    private const MESSAGES = [
        'required' => 'The {field} field is required.',
        'required_with' => 'The {field} field is required when {param} is present.',
        'required_without' => 'The {field} field is required when {param} is not present.',
        'min_length' => 'The {field} field must be at least {param} characters in length.',
        'max_length' => 'The {field} field cannot exceed {param} characters in length.',
        'exact_length' => 'The {field} field must be exactly {param} characters in length.',
        'valid_email' => 'The {field} field must contain a valid email address.',
        'valid_emails' => 'The {field} field must contain all valid email addresses.',
        'valid_url' => 'The {field} field must contain a valid URL.',
        'valid_url_strict' => 'The {field} field must contain a valid URL.',
        'valid_ip' => 'The {field} field must contain a valid IP.',
        'valid_json' => 'The {field} field must contain a valid json.',
        'valid_date' => 'The {field} field must contain a valid date.',
        'numeric' => 'The {field} field must contain only numbers.',
        'integer' => 'The {field} field must contain an integer.',
        'decimal' => 'The {field} field must contain a decimal number.',
        'is_natural' => 'The {field} field must only contain digits.',
        'is_natural_no_zero' => 'The {field} field must only contain digits and must be greater than zero.',
        'alpha' => 'The {field} field may only contain alphabetical characters.',
        'alpha_space' => 'The {field} field may only contain alphabetical characters and spaces.',
        'alpha_numeric' => 'The {field} field may only contain alphanumeric characters.',
        'alpha_numeric_space' => 'The {field} field may only contain alphanumeric and space characters.',
        'alpha_dash' => 'The {field} field may only contain alphanumeric, underscore, and dash characters.',
        'in_list' => 'The {field} field must be one of: {param}.',
        'not_in_list' => 'The {field} field must not be one of: {param}.',
        'regex_match' => 'The {field} field is not in the correct format.',
        'greater_than' => 'The {field} field must contain a number greater than {param}.',
        'greater_than_equal_to' => 'The {field} field must contain a number greater than or equal to {param}.',
        'less_than' => 'The {field} field must contain a number less than {param}.',
        'less_than_equal_to' => 'The {field} field must contain a number less than or equal to {param}.',
        'matches' => 'The {field} field does not match the {param} field.',
        'differs' => 'The {field} field must differ from the {param} field.',
        'string' => 'The {field} field must be a valid string.',
        'custom' => 'The {field} field is invalid.',
    ];

    /**
     * Register a custom rule: fn (mixed $value, ?string $param, array $data): bool
     *
     * @param callable(mixed, ?string, array<string, mixed>): bool $callback
     */
    public function addRule(string $name, callable $callback, ?string $message = null): self
    {
        $this->custom[$name] = $callback;
        if ($message !== null) {
            $this->messages[$name] = $message;
        }

        return $this;
    }

    /** @var array<string, string> */
    private array $messages = [];

    public function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $definition) {
            $label = $definition['label'] ?? $field;
            $present = array_key_exists($field, $data);
            $value = $data[$field] ?? null;
            $list = self::splitRules($definition['rules']);
            $names = array_map(static fn (array $r): string => $r[0], $list);
            $isEmpty = $value === null || $value === '' || $value === [];

            if (! $present && ! array_intersect($names, ['required', 'required_with', 'required_without'])) {
                continue;
            }
            if (in_array('if_exist', $names, true) && ! $present) {
                continue;
            }
            if ($isEmpty && in_array('permit_empty', $names, true)) {
                continue;
            }

            foreach ($list as [$rule, $param]) {
                if (in_array($rule, ['permit_empty', 'if_exist', 'trim'], true)) {
                    continue;
                }
                // Database rules (is_unique / is_not_unique) are handled by uniqueFields() or the CI4 validator.
                if (in_array($rule, ['is_unique', 'is_not_unique'], true)) {
                    continue;
                }
                if ($isEmpty && ! in_array($rule, ['required', 'required_with', 'required_without'], true)) {
                    // Like CodeIgniter's "permit_empty" default for non-required fields that were sent empty.
                    if (! in_array('required', $names, true)) {
                        break;
                    }
                }
                if (! $this->check($rule, $param, $value, $data)) {
                    $template = $this->messages[$rule] ?? self::MESSAGES[$rule] ?? self::MESSAGES['custom'];
                    $errors[$field] = strtr($template, ['{field}' => $label, '{param}' => (string) $param]);
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * @return list<array{0: string, 1: string|null}>
     */
    public static function splitRules(string $rules): array
    {
        $result = [];
        // Split on "|" that is not inside brackets (regex_match[/a|b/]).
        preg_match_all('/([a-z_][a-z0-9_]*)(?:\[((?:[^\[\]]|\[[^\]]*\])*)\])?/i', $rules, $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            $result[] = [strtolower($match[1]), $match[2] ?? null];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function check(string $rule, ?string $param, mixed $value, array $data): bool
    {
        if (isset($this->custom[$rule])) {
            return (bool) ($this->custom[$rule])($value, $param, $data);
        }

        $string = is_scalar($value) ? (string) $value : '';
        $isNumeric = is_int($value) || is_float($value) || (is_string($value) && is_numeric($value));

        return match ($rule) {
            'required' => ! ($value === null || $value === '' || $value === []),
            'required_with' => $this->requiredWith($value, (string) $param, $data, true),
            'required_without' => $this->requiredWith($value, (string) $param, $data, false),
            'min_length' => mb_strlen($string) >= (int) $param,
            'max_length' => mb_strlen($string) <= (int) $param,
            'exact_length' => in_array(mb_strlen($string), array_map('intval', explode(',', (string) $param)), true),
            'valid_email' => filter_var($string, FILTER_VALIDATE_EMAIL) !== false,
            'valid_emails' => array_reduce(explode(',', $string), static fn (bool $c, string $e): bool => $c && filter_var(trim($e), FILTER_VALIDATE_EMAIL) !== false, true),
            'valid_url', 'valid_url_strict' => filter_var($string, FILTER_VALIDATE_URL) !== false
                && in_array(strtolower((string) parse_url($string, PHP_URL_SCHEME)), $param ? explode(',', $param) : ['http', 'https'], true),
            'valid_ip' => filter_var($string, FILTER_VALIDATE_IP, match ($param) {
                'ipv4' => FILTER_FLAG_IPV4,
                'ipv6' => FILTER_FLAG_IPV6,
                default => 0,
            }) !== false,
            'valid_json' => is_array($value) || (is_string($value) && json_validate($value)),
            'valid_date' => $this->validDate($string, $param),
            'numeric' => $isNumeric,
            'integer' => is_int($value) || (bool) preg_match('/^[\-+]?\d+$/', $string),
            'decimal' => $isNumeric || (bool) preg_match('/^[\-+]?\d*\.?\d+$/', $string),
            'is_natural' => (bool) preg_match('/^\d+$/', $string),
            'is_natural_no_zero' => (bool) preg_match('/^\d+$/', $string) && (int) $string !== 0,
            'alpha' => (bool) preg_match('/^[A-Za-z]+$/', $string),
            'alpha_space' => (bool) preg_match('/^[A-Za-z ]+$/', $string),
            'alpha_numeric' => (bool) preg_match('/^[A-Za-z0-9]+$/', $string),
            'alpha_numeric_space' => (bool) preg_match('/^[A-Za-z0-9 ]+$/', $string),
            'alpha_dash' => (bool) preg_match('/^[A-Za-z0-9_-]+$/', $string),
            'in_list' => in_array($string, array_map('trim', explode(',', (string) $param)), true),
            'not_in_list' => ! in_array($string, array_map('trim', explode(',', (string) $param)), true),
            'regex_match' => $this->regex((string) $param, $string),
            'greater_than' => $isNumeric && (float) $value > (float) $param,
            'greater_than_equal_to' => $isNumeric && (float) $value >= (float) $param,
            'less_than' => $isNumeric && (float) $value < (float) $param,
            'less_than_equal_to' => $isNumeric && (float) $value <= (float) $param,
            'matches' => array_key_exists((string) $param, $data) && $data[$param] === $value,
            'differs' => ! array_key_exists((string) $param, $data) || $data[$param] !== $value,
            'string' => is_string($value),
            default => throw new ConfigurationException('Unknown validation rule "' . $rule . '". Register it with Validator::addRule().'),
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requiredWith(mixed $value, string $param, array $data, bool $with): bool
    {
        if (! ($value === null || $value === '' || $value === [])) {
            return true;
        }
        foreach (explode(',', $param) as $other) {
            $filled = isset($data[trim($other)]) && $data[trim($other)] !== '' && $data[trim($other)] !== [];
            if ($with ? $filled : ! $filled) {
                return false;
            }
        }

        return true;
    }

    private function validDate(string $value, ?string $format): bool
    {
        if ($format === null || $format === '') {
            return strtotime($value) !== false;
        }
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);

        return $date !== false && $date->format($format) === $value;
    }

    private function regex(string $pattern, string $value): bool
    {
        if ($pattern === '' || @preg_match($pattern, '') === false) {
            throw new ConfigurationException('Invalid regex_match pattern.');
        }

        return (bool) preg_match($pattern, $value);
    }
}
