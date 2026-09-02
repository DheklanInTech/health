import Nav from "@/components/Nav";
import Hero from "@/components/Hero";
import Gallery, { type GallerySlide } from "@/components/Gallery";
import ScreeningForm from "@/components/ScreeningForm";
import Footer from "@/components/Footer";

const slides: GallerySlide[] = [
  { src: "/s4.jpg", alt: "HSV vaccine trial photo 2" },
  { src: "/s1.jpg", alt: "HSV vaccine trial photo 1" },
  { src: "/s3.jpg", alt: "HSV vaccine trial photo 3" },
   { src: "/s2.jpg", alt: "HSV vaccine trial photo 3" },
];

export default function HsvVaccinesTrialsPage() {
  return (
    <div className="flex flex-col min-h-screen">
      <Nav />
      <Hero title="HSV 1&2. Vaccines Trials" image="/hrr.jpg" breadcrumb="HSV 1&2. Vaccines Trials" />

      <main className="max-w-6xl mx-auto px-6 md:px-12 py-10 md:py-16 w-full box-border">
        <Gallery slides={slides} />

        <section className="max-w-2xl mb-16">
          <p className="text-lg leading-relaxed text-ink2-800">
            Are you interested in making a difference in the fight against HSV-1 and HSV-2? Join
            our clinical trials for our innovative HSV vaccine research. By participating,
            you&rsquo;ll help advance the development of a potential breakthrough vaccine that
            could protect millions from the spread of these viruses. Our trials offer you the
            opportunity to contribute to groundbreaking medical research while receiving expert
            care and support throughout the process. Be part of a pivotal moment in
            healthcare—sign up today and help us bring hope to those affected by HSV.
          </p>
        </section>

        <hr className="border-t-2 border-divider mb-12" />

        <ScreeningForm />
      </main>

      <Footer />
    </div>
  );
}
