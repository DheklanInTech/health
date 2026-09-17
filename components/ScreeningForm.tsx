"use client";

import { useState } from "react";
import { useFormik, type FormikErrors, type FormikTouched } from "formik";

export type FieldConfig =
  | { type: "email" | "tel" | "number"; name: string; label: string; required?: boolean; max?: number }
  | { type: "radio"; name: string; label: string; required?: boolean; options: string[] }
  | { type: "yesno"; name: string; label: string; required?: boolean };

export type StepConfig = { label: string; fields: FieldConfig[] };

function fieldValueKeys(field: FieldConfig): string[] {
  return  [field.name];
}

const WEIGHT_LOSS_STEPS: StepConfig[] = [
  {
    label: "Personal Info",
    fields: [
      { type: "email", name: "email", label: "Email", required: true },
      { type: "tel", name: "phone", label: "Phone Number" },
      { type: "number", name: "age", label: "Age", required: true, max: 120 },
      { type: "radio", name: "sex", label: "Sex", options: ["Male", "Female", "Not Specified"] },
    ],
  },
  {
    label: "Health Metrics",
    fields: [
      { type: "number", name: "weight", label: "Weight (lb)", required: true },
      { type: "number", name: "height", label: "Height (ft)", required: true },
    ],
  },
  {
    label: "Medical History",
    fields: [
      { type: "yesno", name: "pancreatitis", label: "History of Pancreatitis?", required: true },
      { type: "yesno", name: "gallbladder", label: "History of Gall Bladder Stone?", required: true },
      { type: "yesno", name: "hypertensive", label: "Are You Hypertensive?", required: true },
      { type: "yesno", name: "diabetic", label: "Are You Diabetic?", required: true },
    ],
  },
  {
    label: "Mental Health",
    fields: [
      { type: "yesno", name: "mentalillness", label: "Any History of Mental Illness?", required: true },
      { type: "yesno", name: "mdd", label: "Do You Have Major Depressive Disorder?", required: true },
      { type: "yesno", name: "bipolar", label: "Do You Have a Bipolar Disorder?", required: true },
      { type: "yesno", name: "schizophrenic", label: "Are You Schizophrenic?", required: true },
      { type: "yesno", name: "anxiety", label: "Do You Suffer From Serious Anxiety?", required: true },
      {
        type: "yesno",
        name: "adhd",
        label: "Do You Suffer From Attention Hypersensitivity Disorder?",
        required: true,
      },
    ],
  },
];

export const ALZHEIMERS_STEPS: StepConfig[] = [
  {
    label: "Personal Info",
    fields: [
      { type: "email", name: "email", label: "Email", required: true },
      { type: "tel", name: "phone", label: "Phone Number" },
      { type: "number", name: "age", label: "Age", required: true, max: 120 },
      { type: "radio", name: "sex", label: "Sex", options: ["Male", "Female"] },
    ],
  },
  {
    label: "Health & Eligibility",
    fields: [
      { type: "number", name: "weight", label: "Weight (lb)", required: true },
      { type: "number", name: "height", label: "Height (ft)", required: true },
      { type: "yesno", name: "over65", label: "Are You 65 years or Older?", required: true },
      { type: "yesno", name: "dementia", label: "Any History of Dementia?", required: true },
    ],
  },
];

export const FLU_VACCINE_STEPS: StepConfig[] = [
  {
    label: "Personal Info",
    fields: [
      { type: "email", name: "email", label: "Email", required: true },
      { type: "tel", name: "phone", label: "Phone Number" },
      { type: "number", name: "age", label: "Age", required: true, max: 120 },
      { type: "radio", name: "sex", label: "Sex", options: ["Male", "Female", "Not Specified"] },
      { type: "number", name: "weight", label: "Weight (lb)", required: true },
      { type: "number", name: "height", label: "Height (ft)", required: true },
    ],
  },
  {
    label: "Asthma",
    fields: [
      {
        type: "yesno",
        name: "asthmaDiagnosis",
        label: "Have you received a physician-confirmed diagnosis of asthma?",
        required: true,
      },
      {
        type: "radio",
        name: "asthmaSeverity",
        label: "How is your asthma currently classified?",
        options: [
          "Intermittent",
          "Mild persistent",
          "Moderate persistent",
          "Severe persistent",
          "Not applicable / undiagnosed",
        ],
        required: true,
      },
      {
        type: "yesno",
        name: "asthmaController",
        label:
          "Are you on daily controller therapy (inhaled corticosteroid, ICS/LABA, leukotriene receptor antagonist)?",
        required: true,
      },
      {
        type: "yesno",
        name: "asthmaBiologic",
        label:
          "Do you receive a biologic agent for severe asthma (e.g. omalizumab, mepolizumab, dupilumab, benralizumab)?",
        required: true,
      },
      {
        type: "radio",
        name: "asthmaExacerbations",
        label:
          "How many asthma exacerbations requiring systemic (oral or IV) corticosteroids have you had in the past 12 months?",
        options: ["None", "1", "2 to 3", "4 or more"],
        required: true,
      },
      {
        type: "yesno",
        name: "asthmaHospitalisation",
        label:
          "Have you ever been hospitalised, mechanically ventilated, or admitted to intensive care for status asthmaticus?",
        required: true,
      },
      {
        type: "yesno",
        name: "asthmaSaba",
        label:
          "Do you use a short-acting beta-agonist (e.g. salbutamol/albuterol) rescue inhaler more than twice per week?",
        required: true,
      },
      {
        type: "yesno",
        name: "asthmaAspirinSensitivity",
        label:
          "Do you have aspirin-exacerbated respiratory disease (NSAID-sensitive asthma), nasal polyposis, or allergic rhinitis?",
        required: true,
      },
    ],
  },
  {
    label: "Cardiovascular Disease",
    fields: [
      {
        type: "yesno",
        name: "cvdDiagnosis",
        label:
          "Have you been diagnosed with cardiovascular disease (coronary artery disease, heart failure, valvular or peripheral arterial disease)?",
        required: true,
      },
      {
        type: "yesno",
        name: "cvdMiRevascularisation",
        label:
          "Have you had a myocardial infarction, percutaneous coronary intervention (stent), or coronary artery bypass graft?",
        required: true,
      },
      {
        type: "yesno",
        name: "cvdHeartFailure",
        label:
          "Have you been diagnosed with congestive heart failure (reduced or preserved ejection fraction)?",
        required: true,
      },
      {
        type: "radio",
        name: "cvdNyhaClass",
        label: "What is your NYHA functional class?",
        options: [
          "Class I - no limitation of physical activity",
          "Class II - slight limitation, symptoms on ordinary activity",
          "Class III - marked limitation, symptoms on minimal activity",
          "Class IV - symptoms at rest",
          "Not applicable / undiagnosed",
        ],
        required: true,
      },
      {
        type: "yesno",
        name: "cvdArrhythmia",
        label:
          "Do you have atrial fibrillation, atrial flutter, or another clinically significant arrhythmia?",
        required: true,
      },
      {
        type: "yesno",
        name: "cvdHypertension",
        label:
          "Have you been diagnosed with hypertension, and are you taking antihypertensive medication?",
        required: true,
      },
      {
        type: "yesno",
        name: "cvdStrokeTia",
        label: "Have you had a cerebrovascular accident (stroke) or transient ischaemic attack?",
        required: true,
      },
      {
        type: "yesno",
        name: "cvdAnticoagulation",
        label:
          "Are you on anticoagulant or antiplatelet therapy (e.g. warfarin, apixaban, rivaroxaban, clopidogrel, aspirin)?",
        required: true,
      },
      {
        type: "yesno",
        name: "cvdDevice",
        label:
          "Do you have an implanted cardiac device (pacemaker, implantable cardioverter-defibrillator) or a prosthetic heart valve?",
        required: true,
      },
      {
        type: "yesno",
        name: "cvdCardiacEventRecent",
        label:
          "Have you experienced an acute cardiac event, decompensation, or cardiac surgery within the past 3 months?",
        required: true,
      },
    ],
  },
  {
    label: "COPD",
    fields: [
      {
        type: "yesno",
        name: "copdDiagnosis",
        label:
          "Have you been diagnosed with chronic obstructive pulmonary disease (chronic bronchitis or emphysema)?",
        required: true,
      },
      {
        type: "yesno",
        name: "copdSpirometry",
        label:
          "Has spirometry confirmed persistent airflow obstruction (post-bronchodilator FEV1/FVC below 0.70)?",
        required: true,
      },
      {
        type: "radio",
        name: "copdGoldStage",
        label: "What is your GOLD spirometric stage (based on FEV1 percent predicted)?",
        options: [
          "GOLD 1 - mild (FEV1 at or above 80%)",
          "GOLD 2 - moderate (FEV1 50-79%)",
          "GOLD 3 - severe (FEV1 30-49%)",
          "GOLD 4 - very severe (FEV1 below 30%)",
          "Unknown / not applicable",
        ],
        required: true,
      },
      {
        type: "radio",
        name: "copdExacerbations",
        label:
          "How many COPD exacerbations requiring antibiotics, systemic corticosteroids, or hospitalisation have you had in the past 12 months?",
        options: ["None", "1", "2", "3 or more"],
        required: true,
      },
      {
        type: "yesno",
        name: "copdOxygen",
        label:
          "Do you use long-term supplemental oxygen or non-invasive ventilation (CPAP/BiPAP) at home?",
        required: true,
      },
      {
        type: "yesno",
        name: "copdBronchodilators",
        label:
          "Are you on maintenance bronchodilator therapy (LAMA, LABA, or triple ICS/LABA/LAMA inhaler)?",
        required: true,
      },
      {
        type: "radio",
        name: "copdSmokingStatus",
        label: "What is your smoking status?",
        options: ["Never smoker", "Former smoker", "Current smoker"],
        required: true,
      },
      {
        type: "radio",
        name: "copdPackYears",
        label: "What is your cumulative smoking exposure in pack-years?",
        options: ["None", "Fewer than 10", "10 to 20", "21 to 40", "More than 40"],
        required: true,
      },
      {
        type: "yesno",
        name: "copdChronicHypoxaemia",
        label:
          "Have you been diagnosed with chronic respiratory failure, pulmonary hypertension, or cor pulmonale?",
        required: true,
      },
      {
        type: "yesno",
        name: "copdPneumoniaHistory",
        label:
          "Have you had pneumonia or a lower respiratory tract infection requiring treatment in the past 6 months?",
        required: true,
      },
    ],
  },
  {
    label: "Vaccine Eligibility",
    fields: [
      {
        type: "yesno",
        name: "fluPriorVaccine",
        label: "Have you received a seasonal influenza vaccine within the past 6 months?",
        required: true,
      },
      {
        type: "yesno",
        name: "fluAnaphylaxis",
        label:
          "Have you ever had a severe allergic reaction (anaphylaxis) to an influenza vaccine or any of its components?",
        required: true,
      },
      {
        type: "yesno",
        name: "fluEggAllergy",
        label: "Do you have a known allergy to egg protein (ovalbumin), gentamicin, or gelatin?",
        required: true,
      },
      {
        type: "yesno",
        name: "fluGuillainBarre",
        label:
          "Have you ever developed Guillain-Barre syndrome within 6 weeks of receiving any vaccine?",
        required: true,
      },
      {
        type: "yesno",
        name: "fluImmunosuppression",
        label:
          "Are you immunocompromised, or taking immunosuppressive therapy (systemic corticosteroids, chemotherapy, biologics, or post-transplant medication)?",
        required: true,
      },
      {
        type: "yesno",
        name: "fluFebrileIllness",
        label:
          "Do you currently have a moderate to severe febrile illness (temperature at or above 100.4 F / 38 C)?",
        required: true,
      },
      {
        type: "yesno",
        name: "fluBleedingDisorder",
        label:
          "Do you have thrombocytopenia or a bleeding disorder that contraindicates intramuscular injection?",
        required: true,
      },
      {
        type: "yesno",
        name: "fluPregnancy",
        label: "Are you currently pregnant, breastfeeding, or planning a pregnancy?",
        required: true,
      },
      {
        type: "yesno",
        name: "fluConsent",
        label:
          "Do you consent to a screening review of your medical records and to attend scheduled follow-up visits?",
        required: true,
      },
    ],
  },
];
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

const ID_CHARS = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";

function generateApplicationId() {
  let code = "";
  for (let i = 0; i < 8; i++) {
    code += ID_CHARS[Math.floor(Math.random() * ID_CHARS.length)];
  }
  return `RH-${code}`;
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
      className={`w-full box-border border  bg-white px-3 py-2.5 focus:outline-2 focus:outline-accent focus:outline-offset-2 ${className ?? ""}`}
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
        <div className="font-heading font-extrabold text-xl tracking-widest mb-8">{applicationId}</div>
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
  steps?: StepConfig[];
  title?: string;
};

export default function ScreeningForm({ steps = WEIGHT_LOSS_STEPS, title = "Application" }: ScreeningFormProps) {
  const [applicationId, setApplicationId] = useState<string | null>(null);
  const initialValues = buildInitialValues(steps);

  const formik = useFormik<Record<string, string>>({
    initialValues,
    validate: (values) => validateSteps(steps, values),
    onSubmit: (values) => {
      console.log("Screening application submitted", values);
      setApplicationId(generateApplicationId());
    },
  });

  const { values, errors, touched, handleChange, handleBlur, setFieldValue, setTouched } = formik;

  const [step, setStep] = useState(0);
  const isMultiStep = steps.length > 1;
  const stepFieldKeys = steps[step].fields.flatMap(fieldValueKeys);

  const closeSuccessModal = () => {
    setApplicationId(null);
    formik.resetForm();
    setStep(0);
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

        <div className="flex gap-4 mt-12">
          {step > 0 && (
            <button
              type="button"
              onClick={goBack}
              className="border-2 border-ink text-ink font-heading font-extrabold text-sm px-5 py-2.5 hover:bg-ink2-200"
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
              className="bg-accent text-white font-heading font-extrabold text-sm px-5 py-2.5 hover:bg-accent-600 active:bg-accent-700"
            >
              Submit
            </button>
          )}
        </div>
      </form>

      {applicationId && <SuccessModal applicationId={applicationId} onClose={closeSuccessModal} />}
    </section>
  );
}
