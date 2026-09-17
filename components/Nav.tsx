"use client";

import { usePathname } from "next/navigation";
import { useState } from "react";
import TopBar from "./TopBar";

const links = [
  { label: "Weight Loss Trials", href: "/" },
  { label: "Alzheimer's Trials", href: "/alzheimers-trials" },
  { label: "HSV 1&2. Vaccines Trials", href: "/hsv-vaccines-trials" },
  { label: "Flu Vaccine", href: "/flu-vaccine" },
];


function MenuIcon({ open }: { open: boolean }) {
  return (
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      {open ? (
        <>
          <line x1="18" y1="6" x2="6" y2="18" />
          <line x1="6" y1="6" x2="18" y2="18" />
        </>
      ) : (
        <>
          <line x1="3" y1="6" x2="21" y2="6" />
          <line x1="3" y1="12" x2="21" y2="12" />
          <line x1="3" y1="18" x2="21" y2="18" />
        </>
      )}
    </svg>
  );
}

export default function Nav() {
  const [menuOpen, setMenuOpen] = useState(false);
  const pathname = usePathname();

  return (
    <>
      <TopBar />

      <nav className="flex items-center justify-between px-4 md:px-12 py-4 md:py-5 border-b-2 border-divider">
        <div className="flex items-center gap-3">
          <div className="w-9 h-9 bg-ink flex items-center justify-center shrink-0">
            <span className="text-bg font-heading font-extrabold md:text-lg text-sm">RH</span>
          </div>
          <div className="leading-tight">
            <div className="font-heading font-extrabold text-[12px] md:text-[15px] tracking-wide">
              REVIVING HUMANITY
            </div>
            <div className="text-[10px] md:text-[11px] tracking-widest text-ink2-700">FOUNDATION</div>
          </div>
        </div>

        <div className="hidden md:flex items-center gap-10">
          {links.map((l) =>
            pathname === l.href ? (
              <a
                key={l.label}
                href={l.href}
                className="!text-accent-500 font-bold border-b-2 border-accent-500 pb-1 no-underline"
              >
                {l.label}
              </a>
            ) : (
              <a key={l.label} href={l.href} className="!text-ink font-medium no-underline">
                {l.label}
              </a>
            )
          )}
        </div>

        <div className="flex items-center gap-4 md:gap-6">
          <button className="bg-accent text-white font-heading font-extrabold text-xs md:text-sm px-3.5 md:px-5 py-2 md:py-2.5 hover:bg-accent-600 active:bg-accent-700">
            Donate Now
          </button>
          <button
            type="button"
            aria-label={menuOpen ? "Close menu" : "Open menu"}
            aria-expanded={menuOpen}
            onClick={() => setMenuOpen((o) => !o)}
            className="md:hidden w-9 h-9 flex items-center justify-center border-2 border-ink shrink-0"
          >
            <MenuIcon open={menuOpen} />
          </button>
        </div>
      </nav>

      {menuOpen && (
        <div className="md:hidden border-b-2 border-divider bg-bg px-6 py-5 flex flex-col gap-5">
          {links.map((l) => (
            <a
              key={l.label}
              href={l.href}
              onClick={() => setMenuOpen(false)}
              className={
                pathname === l.href
                  ? "!text-accent-500 font-bold no-underline"
                  : "!text-ink font-medium no-underline"
              }
            >
              {l.label}
            </a>
          ))}
        </div>
      )}
    </>
  );
}
