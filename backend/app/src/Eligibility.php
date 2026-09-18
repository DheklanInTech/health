<?php

declare(strict_types=1);

namespace Olisa;

/**
 * Automated pre-screening.
 *
 * Runs once at intake and its verdict is stored on the submission so
 * coordinators can triage a large inbox quickly. It is decision *support*,
 * never a decision: nothing is auto-rejected, every submission stays visible,
 * and a human sets the final status.
 *
 * Rules are declarative — hand a protocol change to a developer and they edit
 * one array here.
 */
final class Eligibility
{
    public const EXCLUSION = 'exclusion';
    public const CAUTION   = 'caution';
    public const INFO      = 'info';

    /**
     * Rule sets keyed by trial slug. Each rule is
     * [level, label, callable(answers, bmi, age): bool, fields].
     *
     * `fields` names the answers the rule was derived from, so the dashboard can
     * mark the specific answer that caused a flag rather than guessing that
     * "Yes" is always the bad answer — for a question like "Are you 65 or
     * older?" the opposite is true.
     *
     * @return array<string, array<int, array{0: string, 1: string, 2: callable, 3: array<int, string>}>>
     */
    private static function rules(): array
    {
        $yes = static fn(array $a, string $key): bool => ($a[$key] ?? '') === 'Yes';

        $ageFloor = [
            self::EXCLUSION,
            'Under 18 — outside adult protocol',
            static fn(array $a, ?float $bmi, ?int $age): bool => $age !== null && $age < 18,
            ['age'],
        ];

        return [
            'weight-loss' => [
                $ageFloor,
                [self::EXCLUSION, 'History of pancreatitis — GLP-1 contraindication',
                    static fn(array $a) => $yes($a, 'pancreatitis'), ['pancreatitis']],
                [self::EXCLUSION, 'BMI below 27 — under study threshold',
                    static fn(array $a, ?float $bmi) => $bmi !== null && $bmi < 27.0, ['weight', 'height']],
                [self::CAUTION, 'History of gallbladder stones',
                    static fn(array $a) => $yes($a, 'gallbladder'), ['gallbladder']],
                [self::CAUTION, 'Hypertensive — needs blood-pressure clearance',
                    static fn(array $a) => $yes($a, 'hypertensive'), ['hypertensive']],
                [self::CAUTION, 'Bipolar disorder reported',
                    static fn(array $a) => $yes($a, 'bipolar'), ['bipolar']],
                [self::CAUTION, 'Schizophrenia reported',
                    static fn(array $a) => $yes($a, 'schizophrenic'), ['schizophrenic']],
                [self::CAUTION, 'Major depressive disorder reported',
                    static fn(array $a) => $yes($a, 'mdd'), ['mdd']],
                [self::INFO, 'BMI 40+ — Class III obesity',
                    static fn(array $a, ?float $bmi) => $bmi !== null && $bmi >= 40.0, ['weight', 'height']],
                [self::INFO, 'Diabetic — stratify to diabetic arm',
                    static fn(array $a) => $yes($a, 'diabetic'), ['diabetic']],
            ],

            'alzheimers' => [
                $ageFloor,
                [self::EXCLUSION, 'Under 65 — below protocol age floor',
                    static fn(array $a, ?float $bmi, ?int $age) =>
                        ($a['over65'] ?? '') === 'No' || ($age !== null && $age < 65), ['over65', 'age']],
                [self::INFO, 'Existing dementia diagnosis',
                    static fn(array $a) => $yes($a, 'dementia'), ['dementia']],
            ],

            'hsv' => [
                $ageFloor,
                [self::EXCLUSION, 'Immunocompromised or on immunosuppressive therapy',
                    static fn(array $a) => $yes($a, 'immunosuppressed'), ['immunosuppressed']],
                [self::EXCLUSION, 'Pregnant, breastfeeding or planning pregnancy',
                    static fn(array $a) => $yes($a, 'pregnant'), ['pregnant']],
                [self::EXCLUSION, 'Another vaccine trial within 6 months — washout not met',
                    static fn(array $a) => $yes($a, 'priortrial'), ['priortrial']],
                [self::INFO, 'On suppressive antiviral therapy',
                    static fn(array $a) => $yes($a, 'antiviral'), ['antiviral']],
                [self::INFO, 'Recurrent outbreak in past 12 months',
                    static fn(array $a) => $yes($a, 'outbreaks'), ['outbreaks']],
            ],

            'flu' => [
                $ageFloor,
                [self::EXCLUSION, 'Anaphylaxis to influenza vaccine, egg protein or component',
                    static fn(array $a) => $yes($a, 'anaphylaxis'), ['anaphylaxis']],
                [self::EXCLUSION, 'Guillain-Barre syndrome following vaccination',
                    static fn(array $a) => $yes($a, 'guillainbarre'), ['guillainbarre']],
                [self::EXCLUSION, 'Immunocompromised or on immunosuppressive therapy',
                    static fn(array $a) => $yes($a, 'immunosuppressed'), ['immunosuppressed']],
                [self::EXCLUSION, 'Influenza vaccine within 6 months — washout not met',
                    static fn(array $a) => $yes($a, 'priorvaccine'), ['priorvaccine']],
                [self::CAUTION, 'Long-term supplemental oxygen therapy',
                    static fn(array $a) => $yes($a, 'oxygentherapy'), ['oxygentherapy']],
                [self::CAUTION, 'Asthma exacerbation on systemic corticosteroids in past 12 months',
                    static fn(array $a) => $yes($a, 'exacerbation'), ['exacerbation']],
                [self::CAUTION, 'Congestive heart failure',
                    static fn(array $a) => $yes($a, 'heartfailure'), ['heartfailure']],
                [self::INFO, 'COPD diagnosis',
                    static fn(array $a) => $yes($a, 'copd'), ['copd']],
                [self::INFO, 'Current smoker',
                    static fn(array $a) => ($a['smoking'] ?? '') === 'Current Smoker', ['smoking']],
            ],
        ];
    }

    /**
     * Height is collected in feet, weight in pounds: BMI = 703 * lb / in².
     */
    public static function bmi(array $answers): ?float
    {
        $weightLb = isset($answers['weight']) ? (float) $answers['weight'] : 0.0;
        $heightFt = isset($answers['height']) ? (float) $answers['height'] : 0.0;
        if ($weightLb <= 0 || $heightFt <= 0) {
            return null;
        }
        $heightIn = $heightFt * 12;
        $bmi = (703 * $weightLb) / ($heightIn * $heightIn);
        if (!is_finite($bmi) || $bmi <= 0 || $bmi > 200) {
            return null;
        }
        return round($bmi, 1);
    }

    public static function bmiCategory(float $bmi): string
    {
        return match (true) {
            $bmi < 18.5 => 'Underweight',
            $bmi < 25   => 'Healthy',
            $bmi < 30   => 'Overweight',
            $bmi < 35   => 'Obesity I',
            $bmi < 40   => 'Obesity II',
            default     => 'Obesity III',
        };
    }

    /**
     * @param array<string, string> $answers
     * @return array{verdict: string, flags: array<int, array{level: string, label: string, fields: array<int, string>}>, bmi: float|null}
     */
    public static function evaluate(string $trial, array $answers): array
    {
        $bmi = self::bmi($answers);
        $age = isset($answers['age']) && is_numeric($answers['age']) ? (int) $answers['age'] : null;

        $flags = [];
        foreach (self::rules()[$trial] ?? [] as [$level, $label, $test, $fields]) {
            try {
                if ($test($answers, $bmi, $age)) {
                    $flags[] = ['level' => $level, 'label' => $label, 'fields' => $fields];
                }
            } catch (\Throwable) {
                // A malformed answer must never break intake — skip the rule.
                continue;
            }
        }

        $levels = array_column($flags, 'level');
        $verdict = in_array(self::EXCLUSION, $levels, true) ? 'excluded'
            : (in_array(self::CAUTION, $levels, true) ? 'review' : 'eligible');

        return ['verdict' => $verdict, 'flags' => $flags, 'bmi' => $bmi];
    }

    /**
     * Field names that caused an exclusion or caution flag, so the detail page
     * can highlight exactly those answers.
     *
     * @param array<int, array<string, mixed>> $flags
     * @return array<int, string>
     */
    public static function flaggedFields(array $flags): array
    {
        $names = [];
        foreach ($flags as $flag) {
            if (!in_array($flag['level'] ?? '', [self::EXCLUSION, self::CAUTION], true)) {
                continue;
            }
            foreach ((array) ($flag['fields'] ?? []) as $field) {
                $names[(string) $field] = true;
            }
        }
        return array_keys($names);
    }

    public static function verdictLabel(string $verdict): string
    {
        return match ($verdict) {
            'eligible' => 'Passes screening',
            'excluded' => 'Likely excluded',
            default    => 'Needs review',
        };
    }
}
