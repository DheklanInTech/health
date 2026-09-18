import Nav from "@/components/Nav";
import Hero from "@/components/Hero";
import Gallery, { type GallerySlide } from "@/components/Gallery";
import ScreeningForm from "@/components/ScreeningForm";
import Footer from "@/components/Footer";

const slides: GallerySlide[] = [
  { src: "/im1.jpg", alt: "Alzheimer's trial participant photo 1" },
  { src: "/im2.jpg", alt: "Alzheimer's trial participant photo 2" },
  { src: "/im3.jpg", alt: "Alzheimer's trial participant photo 3" },
  { src: "/im4.jpg", alt: "Alzheimer's trial participant photo 4" },
];

export default function AlzheimersTrialsPage() {
  return (
    <div className="flex flex-col min-h-screen">
      <Nav />
      <Hero title="Alzheimer's Trials" image="/hrro.jpg" breadcrumb="Alzheimer's Trials" />

      <main className="max-w-6xl mx-auto px-6 md:px-12 py-10 md:py-16 w-full box-border">
        <Gallery slides={slides} />

        <section className="max-w-2xl mb-16">
          <p className="text-lg leading-relaxed text-ink2-800">
            Are you or a loved one affected by Alzheimer&rsquo;s disease? Join our Alzheimer&rsquo;s
            clinical trials and contribute to the future of dementia care. Our trials are designed
            to explore innovative treatments and therapies aimed at slowing or reversing the
            effects of Alzheimer&rsquo;s. By participating, you will not only gain access to
            cutting-edge medical advancements but also play a crucial role in advancing research
            that could benefit countless others. Your involvement can help shape a brighter,
            healthier future for those impacted by Alzheimer&rsquo;s. Take action today—together,
            we can make a difference.
          </p>
        </section>

        <hr className="border-t-2 border-divider mb-12" />

        <ScreeningForm trial="alzheimers" />
      </main>

      <Footer />
    </div>
  );
}
