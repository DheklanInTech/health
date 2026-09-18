<?php

declare(strict_types=1);

namespace Olisa;

/**
 * Server-side validation of a public submission.
 *
 * The React form validates too, but that is a convenience for the visitor —
 * this is the gate that actually matters. Field rules are derived from
 * shared/trials.json, so they are the same rules the form renders.
 *
 * Anything not declared in the trial's field list is discarded rather than
 * stored: a hand-crafted POST cannot smuggle extra columns into the record.
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    /** @var array<string, string> */
    private array $clean = [];

    public function __construct(private string $trial)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return bool true when the payload is valid
     */
    public function validate(array $input): bool
    {
        $this->errors = [];
        $this->clean  = [];

        foreach (Trials::fields($this->trial) as $field) {
            $name     = (string) $field['name'];
            $label    = (string) $field['label'];
            $type     = (string) $field['type'];
            $required = (bool) ($field['required'] ?? false);

            $raw = $input[$name] ?? '';
            $value = is_scalar($raw) ? trim((string) $raw) : '';

            if ($value === '') {
                if ($required) {
                    $this->errors[$name] = $label . ' is required';
                }
                $this->clean[$name] = '';
                continue;
            }

            switch ($type) {
                case 'email':
                    $value = mb_strtolower($value);
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL) || mb_strlen($value) > 255) {
                        $this->errors[$name] = 'Enter a valid email address';
                    }
                    break;

                case 'tel':
                    // Deliberately permissive — international formats vary and a
                    // rejected phone number costs a real participant.
                    $value = preg_replace('/[^0-9+()\-.\s]/', '', $value) ?? '';
                    $digits = preg_replace('/\D/', '', $value) ?? '';
                    if (strlen($digits) < 7 || strlen($digits) > 15) {
                        $this->errors[$name] = 'Enter a valid phone number';
                    }
                    $value = mb_substr($value, 0, 32);
                    break;

                case 'number':
                    if (!is_numeric($value)) {
                        $this->errors[$name] = $label . ' must be a number';
                        break;
                    }
                    $number = (float) $value;
                    $max = isset($field['max']) ? (float) $field['max'] : null;
                    if ($number <= 0 || ($max !== null && $number > $max)) {
                        $clean = preg_replace('/\s*\(.*\)/', '', $label) ?? $label;
                        $this->errors[$name] = 'Enter a valid ' . mb_strtolower($clean);
                        break;
                    }
                    // Normalise "70.0" -> "70" so the dashboard reads cleanly.
                    $value = rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
                    break;

                case 'radio':
                    $options = array_map('strval', (array) ($field['options'] ?? []));
                    if (!in_array($value, $options, true)) {
                        $this->errors[$name] = 'Please select an option';
                    }
                    break;

                case 'yesno':
                    if (!in_array($value, ['Yes', 'No'], true)) {
                        $this->errors[$name] = 'Please select an option';
                    }
                    break;

                default:
                    $value = mb_substr($value, 0, 500);
            }

            $this->clean[$name] = $value;
        }

        return $this->errors === [];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Validated answers, limited to declared fields.
     *
     * @return array<string, string>
     */
    public function clean(): array
    {
        return $this->clean;
    }
}
