"use client";

import Image from "next/image";
import { useCallback, useEffect, useRef, useState } from "react";

export type GallerySlide = { src: string | null; alt: string };

const DEFAULT_SLIDES: GallerySlide[] = [
  { src: "/pt1.jpg", alt: "Trial participant photo 1" },
  { src: "/pt2.jpg", alt: "Trial participant photo 2" },
  { src: "/pt3.jpg", alt: "Trial participant photo 3" },
  { src: "/pt4.jpg", alt: "Trial participant photo 4" },
];

const AUTO_SCROLL_MS = 4000;

export default function Gallery({ slides = DEFAULT_SLIDES }: { slides?: GallerySlide[] }) {
  const trackRef = useRef<HTMLDivElement>(null);
  const slideRef = useRef<HTMLDivElement>(null);
  const [index, setIndex] = useState(0);
  const [paused, setPaused] = useState(false);

  const getMetrics = useCallback(() => {
    const track = trackRef.current;
    const slide = slideRef.current;
    if (!track || !slide) return null;
    const slideWidth = slide.offsetWidth;
    if (slideWidth <= 0) return null;
    const maxIndex = Math.max(0, Math.round((track.scrollWidth - track.clientWidth) / slideWidth));
    return { track, slideWidth, maxIndex };
  }, []);

  const scrollToIndex = useCallback(
    (i: number) => {
      const m = getMetrics();
      if (!m) return;
      const span = m.maxIndex + 1;
      const clamped = ((i % span) + span) % span;
      m.track.scrollTo({ left: clamped * m.slideWidth, behavior: "smooth" });
    },
    [getMetrics]
  );

  const step = useCallback(
    (dir: 1 | -1) => {
      const m = getMetrics();
      if (!m) return;
      const current = Math.round(m.track.scrollLeft / m.slideWidth);
      scrollToIndex(current + dir);
    },
    [getMetrics, scrollToIndex]
  );

  useEffect(() => {
    if (paused) return;
    const id = setInterval(() => step(1), AUTO_SCROLL_MS);
    return () => clearInterval(id);
  }, [paused, step]);

  useEffect(() => {
    const track = trackRef.current;
    if (!track) return;
    let frame: number;
    const onScroll = () => {
      cancelAnimationFrame(frame);
      frame = requestAnimationFrame(() => {
        const m = getMetrics();
        if (m) setIndex(Math.round(m.track.scrollLeft / m.slideWidth));
      });
    };
    track.addEventListener("scroll", onScroll);
    return () => {
      cancelAnimationFrame(frame);
      track.removeEventListener("scroll", onScroll);
    };
  }, [getMetrics]);

  return (
    <section
      className="relative mb-14 bg-ink2-200 overflow-hidden"
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
    >
      <div
        ref={trackRef}
        className="flex h-[380px] md:h-[560px] overflow-x-auto snap-x snap-mandatory scroll-smooth [scrollbar-width:none] [-ms-overflow-style:none] [&::-webkit-scrollbar]:hidden"
      >
        {slides.map((slide, i) => (
          <div
            key={slide.alt}
            ref={i === 0 ? slideRef : undefined}
            className="relative h-full flex-shrink-0 snap-start w-full md:w-1/3"
          >
            {slide.src ? (
              <Image
                src={slide.src}
                alt={slide.alt}
                fill
                sizes="(min-width: 768px) 33vw, 100vw"
                className="object-cover"
              />
            ) : (
              <div className="absolute inset-0 flex items-center justify-center bg-ink2-300 text-ink2-600 text-sm text-center px-4 grayscale">
                {slide.alt}
              </div>
            )}
          </div>
        ))}
      </div>

      <button
        type="button"
        onClick={() => step(-1)}
        aria-label="Previous photo"
        className="absolute left-4 top-1/2 -translate-y-1/2 w-10 h-10 flex items-center justify-center bg-ink/70 text-white hover:bg-accent"
      >
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
          <path d="m15 18-6-6 6-6" />
        </svg>
      </button>
      <button
        type="button"
        onClick={() => step(1)}
        aria-label="Next photo"
        className="absolute right-4 top-1/2 -translate-y-1/2 w-10 h-10 flex items-center justify-center bg-ink/70 text-white hover:bg-accent"
      >
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
          <path d="m9 18 6-6-6-6" />
        </svg>
      </button>

      <div className="absolute bottom-4 left-1/2 -translate-x-1/2 flex gap-2">
        {slides.map((slide, i) => (
          <button
            key={slide.alt}
            type="button"
            onClick={() => scrollToIndex(i)}
            aria-label={`Go to photo ${i + 1}`}
            aria-current={i === index}
            className={`w-2.5 h-2.5 ${i === index ? "bg-accent" : "bg-white/60 hover:bg-white"}`}
          />
        ))}
      </div>
    </section>
  );
}
