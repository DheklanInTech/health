import Image from "next/image";

type HeroProps = {
  title?: string;
  image?: string | null;
  breadcrumb?: string;
};

export default function Hero({
  title = "Weight Loss Trials",
  image = "/wm.jpg",
  breadcrumb,
}: HeroProps) {
  return (
    <header className="relative">
      <div className="relative w-full h-[420px] bg-ink2-300">
        {image ? (
          <Image src={image} alt="" fill priority sizes="100vw" className="object-cover" />
        ) : (
          <div className="absolute inset-0 flex items-center justify-center text-ink2-600 text-sm grayscale">
            {title} hero photo placeholder
          </div>
        )}
        <div className="absolute inset-0 bg-gradient-to-b from-black/15 to-black/55 pointer-events-none" />
        <div className="absolute left-6 md:left-12 bottom-10 pointer-events-none">
          <h1 className="font-heading font-extrabold text-4xl md:text-5xl text-white mb-3">
            {title}
          </h1>
          {breadcrumb ? (
            <div className="text-sm tracking-widest text-white flex items-center gap-2.5">
              <span className="text-accent2-300">{breadcrumb.toUpperCase()}</span>
            </div>
          ) : (
            <div className="text-sm tracking-widest text-white">
              <span className="text-accent2-100">{title.toUpperCase()}</span>
            </div>
          )}
        </div>
      </div>
    </header>
  );
}
