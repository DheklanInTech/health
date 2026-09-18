import { MailIcon, PhoneIcon, PinIcon } from "./icons";

const CONTACT_ICON_CLASS = "w-[18px] h-[18px] text-accent shrink-0 mt-0.5";

export default function Footer() {
  const socials = ["X", "FB", "IN", "IG"];
  return (
    <footer className="bg-ink text-bg px-6 md:px-12 pt-12 md:pt-16 pb-8 mt-auto">
      <div className="max-w-6xl mx-auto grid grid-cols-1 md:grid-cols-2 gap-10 md:gap-16">
        <div>
          <div className="flex items-center gap-3 mb-6">
            <div className="w-9 h-9 bg-bg flex items-center justify-center">
              <span className="text-ink font-heading font-extrabold text-lg">TP</span>
            </div>
            <div className="font-heading font-extrabold text-[15px]">
              TRIAL PATH
            </div>
          </div>
          <p className="text-[15px] leading-relaxed text-ink2-300 max-w-md">
            Trial Path is a non-governmental organization (NGO)
            dedicated to creating positive and sustainable change in communities worldwide.
          </p>
        </div>

        <div>
          <div className="font-heading font-extrabold text-sm tracking-widest mb-6">
            CONTACTS
          </div>
          <div className="flex flex-col gap-4 text-[15px] text-ink2-200">
            <div className="flex items-start gap-3">
              <PinIcon className={CONTACT_ICON_CLASS} />
              <span>9842 S Calhoun Ave Chicago Illinois 60617</span>
            </div>
            <div className="flex items-start gap-3">
              <MailIcon className={CONTACT_ICON_CLASS} />
              <span>info@revivinghumanityfoundation.org</span>
            </div>
            <div className="flex items-start gap-3">
              <PhoneIcon className={CONTACT_ICON_CLASS} />
              <span>+1 (312) 493-7564</span>
            </div>
          </div>
          <div className="flex gap-3 mt-8">
            {socials.map((s) => (
              <a
                key={s}
                href="#"
                className="!text-bg w-9 h-9 border-2 border-ink2-600 flex items-center justify-center font-heading font-bold text-[13px] no-underline"
              >
                {s}
              </a>
            ))}
          </div>
        </div>
      </div>

      <div className="max-w-6xl mx-auto mt-10 md:mt-12 pt-6 border-t-2 border-ink2-800 flex flex-col md:flex-row md:justify-between gap-3 md:gap-0 text-[13px] text-ink2-400">
        <div className="flex flex-wrap gap-4">
          <span>Terms of use</span>
          <span>Privacy Environmental Policy</span>
        </div>
        <div>Copyright © 2023 Trial Path. All Rights Reserved.</div>
      </div>
    </footer>
  );
}
