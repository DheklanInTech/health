"use client";

import { useRef, useState } from "react";
import { useFormik, type FormikErrors, type FormikTouched } from "formik";
import {
  getSteps,
  type FieldConfig,
  type StepConfig,
} from "@/lib/trials";

// Re-exported so existing imports keep working; the definitions now live in
// shared/trials.json, which the PHP backend reads too.
export type { FieldConfig, StepConfig };

/** Where the PHP backend is mounted. Same origin, so a relative path. */
const SUBMIT_ENDPOINT = "/api/submit.php";

function fieldValueKeys(field: FieldConfig): string[] {
  return [field.name];
}

function buildInitialValues(steps: StepConfig[]): Record<string, string> {
  const values: Record<string, string> = {};
  for (const step of steps) {
    for (const field of step.fields) {
      for (const key of fieldValueKeys(field)) values[key] = "";
    }
  }
  return values;
}

function validateSteps(steps: StepConfig[], values: Record<string, string>) {
  const errors: FormikErrors<Record<string, string>> = {};

  for (const step of steps) {
    for (const field of step.fields) {
      if (field.type === "email") {
        const v = values[field.name];
        if (field.required && !v) errors[field.name] = `${field.label} is required`;
        else if (v && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) errors[field.name] = "Enter a valid email address";
      } else if (field.type === "number") {
        const v = values[field.name];
        if (field.required && !v) errors[field.name] = `${field.label} is required`;
        else if (v && (Number(v) <= 0 || (field.max !== undefined && Number(v) > field.max))) {
          errors[field.name] = `Enter a valid ${field.label.replace(/\s*\(.*\)/, "").toLowerCase()}`;
        }
      } else if (field.type === "tel") {
        if (field.required && !values[field.name]) errors[field.name] = `${field.label} is required`;
      } else if (field.type === "radio") {
        if (field.required && !values[field.name]) errors[field.name] = "Please select an option";
      } else if (field.type === "yesno") {
        if (field.required && !values[field.name]) errors[field.name] = "Please select an option";
      }
    }
  }

  return errors;
}

function allTouched(initialValues: Record<string, string>): FormikTouched<Record<string, string>> {
  const touched: Record<string, boolean> = {};
  for (const key of Object.keys(initialValues)) touched[key] = true;
  return touched as FormikTouched<Record<string, string>>;
}

function Field({ children }: { children: React.ReactNode }) {
  return <div className="flex flex-col gap-2">{children}</div>;
}

function Label({ text, required = false }: { text: string; required?: boolean }) {
  return (
    <label className="font-bold">
      {text} {required && <span className="text-red-500">*</span>}
    </label>
  );
}

function ErrorText({ message }: { message?: string }) {
  if (!message) return null;
  return <div className="text-red-500 text-sm">{message}</div>;
}

function TextInput(props: React.InputHTMLAttributes<HTMLInputElement> & { hasError?: boolean }) {
  const { hasError, className, ...rest } = props;
  return (
    <input
      {...rest}
      aria-invalid={hasError || undefined}
      className={`w-full box-border border bg-white px-3 py-2.5 focus:outline-2 focus:outline-accent focus:outline-offset-2 ${className ?? ""}`}
    />
  );
}

function YesNo({
  name,
  value,
  onChange,
  onBlur,
}: {
  name: string;
  value: string;
  onChange: (name: string, value: string) => void;
  onBlur: React.FocusEventHandler<HTMLInputElement>;
}) {
  return (
    <div className="flex gap-8 mt-2">
      {["Yes", "No"].map((opt) => (
        <label key={opt} className="flex items-center gap-2 cursor-pointer">
          <input
            type="radio"
            name={name}
            value={opt}
            checked={value === opt}
            onChange={() => onChange(name, opt)}
            onBlur={onBlur}
            className="accent-accent w-4 h-4"
          />
          {opt}
        </label>
      ))}
    </div>
  );
}

function SuccessModal({ applicationId, onClose }: { applicationId: string; onClose: () => void }) {
  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-ink/60 px-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="success-modal-title"
    >
      <div className="bg-bg border-2 rounded-lg max-w-sm w-full p-10 flex flex-col items-center text-center">
        <div className="success-icon-badge w-16 h-16 bg-accent flex items-center justify-center rounded-full mb-6">
          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="white" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round">
            <path className="success-icon-check" d="M4 12l5 5L20 6" />
          </svg>
        </div>
        <h3 id="success-modal-title" className="font-heading font-extrabold text-2xl mb-2">
          Application Submitted
        </h3>
        <p className="text-ink2-700 mb-6">Thank you. Your application has been received</p>
        <div className="text-xs tracking-widest text-ink2-700 mb-1">YOUR UNIQUE ID</div>
        <div className="font-heading font-extrabold text-xl tracking-widest mb-2">{applicationId}</div>
        <p className="text-xs text-ink2-700 mb-8">
          Keep this reference — quote it if you contact us about your application.
        </p>
        <button
          type="button"
          onClick={onClose}
          className="bg-accent text-white font-heading font-extrabold text-sm px-5 py-2.5 hover:bg-accent-600 active:bg-accent-700 w-full"
        >
          Done
        </button>
      </div>
    </div>
  );
}

type ScreeningFormProps = {
  /** Trial slug from shared/trials.json — decides the questions and the reference prefix. */
  trial?: string;
  /** Explicit step override. Rarely needed; `trial` is the normal way in. */
  steps?: StepConfig[];
  title?: string;
};

export default function ScreeningForm({
  trial = "weight-loss",
  steps: stepsOverride,
  title = "Application",
}: ScreeningFormProps) {
  const steps = stepsOverride ?? getSteps(trial);

  const [applicationId, setApplicationId] = useState<string | null>(null);
  const [submitError, setSubmitError] = useState<string | null>(null);

  // Bots fill forms instantly; a real multi-step questionnaire does not. The
  // backend rejects anything completed in under three seconds.
  const startedAt = useRef<number>(Date.now());
  // Hidden field a person never sees. Anything in it means a bot.
  const honeypot = useRef<string>("");

  const initialValues = buildInitialValues(steps);

  const formik = useFormik<Record<string, string>>({
    initialValues,
    validate: (values) => validateSteps(steps, values),
    onSubmit: async (values, helpers) => {
      setSubmitError(null);
      try {
        const response = await fetch(SUBMIT_ENDPOINT, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            trial,
            answers: values,
            website: honeypot.current,
            elapsedMs: Date.now() - startedAt.current,
          }),
        });

        const data = await response.json().catch(() => null);

        if (response.ok && data?.ok) {
          setApplicationId(String(data.applicationId));
          return;
        }

        // The server re-validates everything; surface its field errors so the
        // visitor sees them on the right questions.
        if (data?.fields && typeof data.fields === "object") {
          helpers.setErrors(data.fields as FormikErrors<Record<string, string>>);
          helpers.setTouched(allTouched(initialValues));
          const firstBadStep = steps.findIndex((s) =>
            s.fields.flatMap(fieldValueKeys).some((k) => (data.fields as Record<string, string>)[k])
          );
          if (firstBadStep !== -1) setStep(firstBadStep);
        }

        setSubmitError(
          typeof data?.error === "string"
            ? data.error
            : "We could not submit your application. Please try again."
        );
      } catch {
        setSubmitError(
          "We could not reach the server. Check your connection and try again."
        );
      }
    },
  });

  const { values, errors, touched, handleChange, handleBlur, setFieldValue, setTouched, isSubmitting } = formik;

  const [step, setStep] = useState(0);
  const isMultiStep = steps.length > 1;
  const stepFieldKeys = steps[step].fields.flatMap(fieldValueKeys);

  const closeSuccessModal = () => {
    setApplicationId(null);
    setSubmitError(null);
    formik.resetForm();
    setStep(0);
    startedAt.current = Date.now();
    honeypot.current = "";
  };

  const touchStepFields = (i: number) => {
    const updates: Record<string, boolean> = {};
    for (const key of steps[i].fields.flatMap(fieldValueKeys)) updates[key] = true;
    setTouched({ ...touched, ...updates });
  };

  const goNext = () => {
    touchStepFields(step);
    const freshErrors = validateSteps(steps, values);
    const hasErrors = stepFieldKeys.some((k) => freshErrors[k]);
    if (!hasErrors) setStep((s) => Math.min(steps.length - 1, s + 1));
  };

  const goBack = () => setStep((s) => Math.max(0, s - 1));

  const handleFormSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    setTouched(allTouched(initialValues));
    const freshErrors = validateSteps(steps, values);
    const stepWithError = steps.findIndex((s) => s.fields.flatMap(fieldValueKeys).some((k) => freshErrors[k]));
    if (stepWithError !== -1) {
      setStep(stepWithError);
      return;
    }
    formik.handleSubmit(e);
  };

  const renderField = (field: FieldConfig) => {
    switch (field.type) {
      case "email":
      case "tel":
      case "number":
        return (
          <Field key={field.name}>
            <Label text={field.label} required={field.required} />
            <TextInput
              type={field.type}
              name={field.name}
              value={values[field.name]}
              onChange={handleChange}
              onBlur={handleBlur}
              hasError={touched[field.name] && !!errors[field.name]}
            />
            <ErrorText message={touched[field.name] ? errors[field.name] : undefined} />
          </Field>
        );
      case "radio":
        return (
          <Field key={field.name}>
            <Label text={field.label} required={field.required} />
            <div className="flex flex-col gap-3 mt-2">
              {field.options.map((opt) => (
                <label key={opt} className="flex items-center gap-2 cursor-pointer">
                  <input
                    type="radio"
                    name={field.name}
                    value={opt}
                    checked={values[field.name] === opt}
                    onChange={() => setFieldValue(field.name, opt)}
                    onBlur={handleBlur}
                    className="accent-accent w-4 h-4"
                  />
                  {opt}
                </label>
              ))}
            </div>
            <ErrorText message={touched[field.name] ? errors[field.name] : undefined} />
          </Field>
        );
      case "yesno":
        return (
          <Field key={field.name}>
            <Label text={field.label} required={field.required} />
            <YesNo
              name={field.name}
              value={values[field.name]}
              onChange={setFieldValue}
              onBlur={handleBlur}
            />
            <ErrorText message={touched[field.name] ? errors[field.name] : undefined} />
          </Field>
        );
      default: {
        const exhaustiveCheck: never = field;
        throw new Error(`Unhandled field type: ${JSON.stringify(exhaustiveCheck)}`);
      }
    }
  };

  return (
    <section>
      <div className="flex items-baseline justify-between mb-8">
        <h2 className="font-heading font-extrabold text-3xl">{title}</h2>
        {isMultiStep && (
          <div className="text-sm text-ink2-700">
            Step {step + 1} of {steps.length}
          </div>
        )}
      </div>

      {isMultiStep && (
        <div
          className="flex border border-ink2-400 mb-12 w-full md:w-fit overflow-x-auto [scrollbar-width:none] [-ms-overflow-style:none] [&::-webkit-scrollbar]:hidden"
          role="radiogroup"
        >
          {steps.map((s, i) => (
            <label
              key={s.label}
              className={`shrink-0 whitespace-nowrap px-4 py-2 text-sm cursor-pointer border-r border-ink2-400 last:border-r-0 ${
                step === i ? "bg-accent text-white font-bold" : "bg-white"
              }`}
            >
              <input
                type="radio"
                name="formstep"
                className="hidden"
                checked={step === i}
                onChange={() => setStep(i)}
              />
              {s.label}
            </label>
          ))}
        </div>
      )}

      <form onSubmit={handleFormSubmit} noValidate>
        <div className="flex flex-col gap-8 max-w-xl">{steps[step].fields.map(renderField)}</div>

        {/* Honeypot. Off-screen rather than display:none — some bots skip
            hidden fields but fill positioned ones. */}
        <div aria-hidden="true" className="absolute left-[-9999px] top-auto w-px h-px overflow-hidden">
          <label htmlFor="website">Leave this field empty</label>
          <input
            type="text"
            id="website"
            name="website"
            tabIndex={-1}
            autoComplete="off"
            onChange={(e) => {
              honeypot.current = e.target.value;
            }}
          />
        </div>

        {submitError && (
          <div
            role="alert"
            className="mt-8 max-w-xl border-l-4 border-red-500 bg-red-50 px-4 py-3 text-sm text-red-700"
          >
            {submitError}
          </div>
        )}

        <div className="flex gap-4 mt-12">
          {step > 0 && (
            <button
              type="button"
              onClick={goBack}
              disabled={isSubmitting}
              className="border-2 border-ink text-ink font-heading font-extrabold text-sm px-5 py-2.5 hover:bg-ink2-200 disabled:opacity-50"
            >
              Back
            </button>
          )}
          {step < steps.length - 1 && (
            <button
              type="button"
              onClick={goNext}
              className="bg-accent text-white font-heading font-extrabold text-sm px-5 py-2.5 hover:bg-accent-600 active:bg-accent-700"
            >
              Next
            </button>
          )}
          {step === steps.length - 1 && (
            <button
              type="submit"
              disabled={isSubmitting}
              className="bg-accent text-white font-heading font-extrabold text-sm px-5 py-2.5 hover:bg-accent-600 active:bg-accent-700 disabled:opacity-60 disabled:cursor-not-allowed"
            >
              {isSubmitting ? "Submitting…" : "Submit"}
            </button>
          )}
        </div>
      </form>

      {applicationId && <SuccessModal applicationId={applicationId} onClose={closeSuccessModal} />}
    </section>
  );
}
