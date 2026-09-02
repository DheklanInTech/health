"use client";

import { FacebookIcon, InstagramIcon, PhoneIcon, PinIcon, PinterestIcon, TwitterIcon } from "./icons";

function DateBadge() {
  const now = new Date();
  const day = now.getDate().toString().padStart(2, "0");
  const month = now.toLocaleString("en-US", { month: "short" }).toUpperCase();
  const year = now.getFullYear();

  return (
    <div className="flex items-center gap-2" suppressHydrationWarning>
      <span className="font-heading font-extrabold text-3xl text-accent leading-none">{day}</span>
      <div className="flex flex-col leading-tight text-[11px] tracking-wide">
        <span className="font-bold">{month}</span>
        <span className="text-ink2-400">{year}</span>
      </div>
    </div>
  );
}

const socials = [
  { Icon: TwitterIcon, label: "Twitter" },
  { Icon: FacebookIcon, label: "Facebook" },
  { Icon: PinterestIcon, label: "Pinterest" },
  { Icon: InstagramIcon, label: "Instagram" },
];

export default function TopBar() {
  return (
    <div className="hidden md:block bg-ink text-bg px-6 lg:px-12 py-2">
      <div className="flex items-center justify-between">
        <DateBadge />

        <div className="flex items-center gap-5">
          <div className="flex items-center gap-2 text-[13px] text-ink2-200">
            <PinIcon className="w-4 h-4 text-accent shrink-0" />
            <span>9842 S Calhoun Ave Chicago Illinois 60617</span>
          </div>

          <span className="w-px h-4 bg-ink2-700" />

          <div className="flex items-center gap-2 text-[13px] text-ink2-200">
            <PhoneIcon className="w-4 h-4 text-accent shrink-0" />
            <span>+1 (312) 493-7564</span>
          </div>

          <div className="flex items-center gap-3 ml-2">
            {socials.map(({ Icon, label }) => (
              <a key={label} href="#" aria-label={label} className="!text-bg hover:!text-accent no-underline">
                <Icon className="w-4 h-4" />
              </a>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}
