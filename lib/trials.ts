/**
 * Typed view over shared/trials.json.
 *
 * That JSON file is the single source of truth for every screening question.
 * The PHP backend reads the same file (backend/src/Trials.php), so a question
 * added there is validated server-side without touching two codebases.
 */

import trialsData from "@/shared/trials.json";

export type FieldConfig =
  | { type: "email" | "tel" | "number"; name: string; label: string; required?: boolean; max?: number }
  | { type: "radio"; name: string; label: string; required?: boolean; options: string[] }
  | { type: "yesno"; name: string; label: string; required?: boolean };

export type StepConfig = { label: string; fields: FieldConfig[] };

export type TrialConfig = {
  slug: string;
  name: string;
  path: string;
  idPrefix: string;
  steps: StepConfig[];
};

type RawTrial = {
  name: string;
  path: string;
  idPrefix: string;
  steps: { label: string; fields: Record<string, unknown>[] }[];
};

const raw = (trialsData as { trials: Record<string, RawTrial> }).trials;

export const TRIALS: Record<string, TrialConfig> = Object.fromEntries(
  Object.entries(raw).map(([slug, trial]) => [
    slug,
    { slug, ...trial, steps: trial.steps as unknown as StepConfig[] },
  ])
);

export type TrialSlug = keyof typeof TRIALS & string;

export const TRIAL_SLUGS = Object.keys(TRIALS);

export function getTrial(slug: string): TrialConfig {
  const trial = TRIALS[slug];
  if (!trial) throw new Error(`Unknown trial "${slug}" — add it to shared/trials.json`);
  return trial;
}

export function getSteps(slug: string): StepConfig[] {
  return getTrial(slug).steps;
}

/** Flat list of every field in a trial, in the order it is asked. */
export function trialFields(slug: string): FieldConfig[] {
  return getSteps(slug).flatMap((step) => step.fields);
}
