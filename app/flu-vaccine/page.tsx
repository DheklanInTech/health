import Nav from "@/components/Nav";
import Hero from "@/components/Hero";
import Gallery, { type GallerySlide } from "@/components/Gallery";
import ScreeningForm from "@/components/ScreeningForm";
import Footer from "@/components/Footer";

const slides: GallerySlide[] = [
  { src: "/fc1.jpg", alt: "Flu vaccine trial participant photo 1" },
  { src: "/fc2.jpg", alt: "Flu vaccine trial participant photo 2" },
  { src: "/fc3.jpg", alt: "Flu vaccine trial participant photo 3" },
  { src: "/fc4.jpg", alt: "Flu vaccine trial participant photo 4" },
];

export default function FluVaccinePage() {
  return (
    <div className="flex flex-col min-h-screen">
      <Nav />
      <Hero title="Flu Vaccine" image="/fc.jpg" breadcrumb="Flu Vaccine" />

      <main className="max-w-6xl mx-auto px-6 md:px-12 py-10 md:py-16 w-full box-border">
        <Gallery slides={slides} />

        <section className="max-w-2xl mb-16">
          <p className="text-lg leading-relaxed text-ink2-800">
            Do you live with a chronic respiratory or cardiac condition? Join our seasonal
            influenza vaccine trials and help us understand how next-generation flu vaccines
            perform in the people who need them most. We are enrolling adults with asthma,
            cardiovascular disease, and chronic obstructive pulmonary disease—groups at
            heightened risk of severe influenza, pneumonia, and acute exacerbations. Participants
            receive thorough clinical screening, expert monitoring throughout the study, and
            support from our research team at every visit. Your participation helps build the
            evidence that shapes immunisation guidance for millions of high-risk patients.
            Complete the screening questionnaire below to see whether you qualify.
          </p>
        </section>

        <hr className="border-t-2 border-divider mb-12" />

        <ScreeningForm trial="flu" />
      </main>

      <Footer />
    </div>
  );
}
