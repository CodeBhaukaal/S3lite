<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\Database;
use App\Http\Exceptions\ValidationException;

/**
 * Rule-string validator: 'required|email|max:255|unique:users,email'.
 */
final class Validator
{
    private array $errors = [];

    public function __construct(
        private array $data,
        private array $rules,
        private array $messages = []
    ) {
    }

    public static function make(array $data, array $rules, array $messages = []): self
    {
        return new self($data, $rules, $messages);
    }

    /**
     * @throws ValidationException
     */
    public static function validate(array $data, array $rules, array $messages = []): array
    {
        $validator = new self($data, $rules, $messages);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        return $validator->validated();
    }

    public function fails(): bool
    {
        $this->run();

        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return !$this->fails();
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        foreach ($this->errors as $messages) {
            return $messages[0] ?? null;
        }

        return null;
    }

    public function validated(): array
    {
        $out = [];

        foreach (array_keys($this->rules) as $field) {
            if (array_key_exists($field, $this->data)) {
                $out[$field] = $this->data[$field];
            }
        }

        return $out;
    }

    private function run(): void
    {
        $this->errors = [];

        foreach ($this->rules as $field => $ruleString) {
            $rules = is_array($ruleString) ? $ruleString : explode('|', $ruleString);
            $value = $this->data[$field] ?? null;

            $isRequired = in_array('required', $rules, true);
            $isNullable = in_array('nullable', $rules, true);
            $isEmpty = $value === null || $value === '' || $value === [];

            if ($isRequired && $isEmpty) {
                $this->addError($field, 'required', ucfirst(str_replace('_', ' ', $field)) . ' is required.');
                continue;
            }

            if ($isEmpty && ($isNullable || !$isRequired)) {
                continue;
            }

            foreach ($rules as $rule) {
                if ($rule === 'required' || $rule === 'nullable') {
                    continue;
                }

                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
                $this->apply($field, (string) $name, $param, $value);
            }
        }
    }

    private function apply(string $field, string $rule, ?string $param, mixed $value): void
    {
        $label = ucfirst(str_replace('_', ' ', $field));

        switch ($rule) {
            case 'string':
                if (!is_string($value)) {
                    $this->addError($field, $rule, "$label must be a string.");
                }
                break;

            case 'int':
            case 'integer':
                if (!is_numeric($value) || (string) (int) $value !== (string) $value) {
                    if (!is_int($value)) {
                        $this->addError($field, $rule, "$label must be an integer.");
                    }
                }
                break;

            case 'numeric':
                if (!is_numeric($value)) {
                    $this->addError($field, $rule, "$label must be numeric.");
                }
                break;

            case 'bool':
            case 'boolean':
                if (!in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false', 'on', 'off'], true)) {
                    $this->addError($field, $rule, "$label must be true or false.");
                }
                break;

            case 'array':
                if (!is_array($value)) {
                    $this->addError($field, $rule, "$label must be an array.");
                }
                break;

            case 'email':
                if (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    $this->addError($field, $rule, "$label must be a valid email address.");
                }
                break;

            case 'url':
                if (!is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
                    $this->addError($field, $rule, "$label must be a valid URL.");
                }
                break;

            case 'ip':
                if (!is_string($value) || filter_var($value, FILTER_VALIDATE_IP) === false) {
                    $this->addError($field, $rule, "$label must be a valid IP address.");
                }
                break;

            case 'uuid':
                if (!is_string($value) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) !== 1) {
                    $this->addError($field, $rule, "$label must be a valid UUID.");
                }
                break;

            case 'min':
                $min = (float) $param;
                if (is_numeric($value) && !is_string($value)) {
                    if ((float) $value < $min) {
                        $this->addError($field, $rule, "$label must be at least $param.");
                    }
                } elseif (is_array($value)) {
                    if (count($value) < $min) {
                        $this->addError($field, $rule, "$label must have at least $param items.");
                    }
                } elseif (mb_strlen((string) $value) < $min) {
                    $this->addError($field, $rule, "$label must be at least $param characters.");
                }
                break;

            case 'max':
                $max = (float) $param;
                if (is_numeric($value) && !is_string($value)) {
                    if ((float) $value > $max) {
                        $this->addError($field, $rule, "$label may not be greater than $param.");
                    }
                } elseif (is_array($value)) {
                    if (count($value) > $max) {
                        $this->addError($field, $rule, "$label may not have more than $param items.");
                    }
                } elseif (mb_strlen((string) $value) > $max) {
                    $this->addError($field, $rule, "$label may not be longer than $param characters.");
                }
                break;

            case 'between':
                [$low, $high] = array_pad(explode(',', (string) $param), 2, '0');
                $length = is_numeric($value) ? (float) $value : mb_strlen((string) $value);
                if ($length < (float) $low || $length > (float) $high) {
                    $this->addError($field, $rule, "$label must be between $low and $high.");
                }
                break;

            case 'in':
                $options = explode(',', (string) $param);
                if (!in_array((string) $value, $options, true)) {
                    $this->addError($field, $rule, "$label must be one of: " . implode(', ', $options) . '.');
                }
                break;

            case 'not_in':
                if (in_array((string) $value, explode(',', (string) $param), true)) {
                    $this->addError($field, $rule, "$label has an invalid value.");
                }
                break;

            case 'regex':
                if (!is_string($value) || @preg_match((string) $param, $value) !== 1) {
                    $this->addError($field, $rule, "$label format is invalid.");
                }
                break;

            case 'alpha_dash':
                if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
                    $this->addError($field, $rule, "$label may only contain letters, numbers, dashes and underscores.");
                }
                break;

            case 'confirmed':
                if (($this->data[$field . '_confirmation'] ?? null) !== $value) {
                    $this->addError($field, $rule, "$label confirmation does not match.");
                }
                break;

            case 'same':
                if (($this->data[(string) $param] ?? null) !== $value) {
                    $this->addError($field, $rule, "$label must match " . str_replace('_', ' ', (string) $param) . '.');
                }
                break;

            case 'date':
                if (strtotime((string) $value) === false) {
                    $this->addError($field, $rule, "$label must be a valid date.");
                }
                break;

            case 'after':
                $compare = strtotime((string) $param) ?: time();
                if ((strtotime((string) $value) ?: 0) <= $compare) {
                    $this->addError($field, $rule, "$label must be a date after $param.");
                }
                break;

            case 'password':
                if (!is_string($value) || mb_strlen($value) < 8) {
                    $this->addError($field, $rule, "$label must be at least 8 characters.");
                } elseif (preg_match('/[A-Za-z]/', $value) !== 1 || preg_match('/\d/', $value) !== 1) {
                    $this->addError($field, $rule, "$label must contain both letters and numbers.");
                }
                break;

            case 'unique':
                [$table, $column, $ignoreId] = array_pad(explode(',', (string) $param), 3, null);
                $column ??= $field;
                $sql = sprintf('SELECT COUNT(*) FROM `%s` WHERE `%s` = ?', $table, $column);
                $bindings = [$value];
                if ($ignoreId !== null && $ignoreId !== '') {
                    $sql .= ' AND id != ?';
                    $bindings[] = (int) $ignoreId;
                }
                if ((int) Database::scalar($sql, $bindings) > 0) {
                    $this->addError($field, $rule, "$label is already taken.");
                }
                break;

            case 'exists':
                [$table, $column] = array_pad(explode(',', (string) $param), 2, null);
                $column ??= 'id';
                $count = (int) Database::scalar(sprintf('SELECT COUNT(*) FROM `%s` WHERE `%s` = ?', $table, $column), [$value]);
                if ($count === 0) {
                    $this->addError($field, $rule, "Selected $label does not exist.");
                }
                break;
        }
    }

    private function addError(string $field, string $rule, string $fallback): void
    {
        $message = $this->messages["$field.$rule"] ?? $this->messages[$field] ?? $fallback;
        $this->errors[$field][] = $message;
    }
}
