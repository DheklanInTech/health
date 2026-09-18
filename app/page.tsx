import Nav from "@/components/Nav";
import Hero from "@/components/Hero";
import Gallery from "@/components/Gallery";
import ScreeningForm from "@/components/ScreeningForm";
import Footer from "@/components/Footer";

export default function WeightLossTrialsPage() {
  return (
    <div className="flex flex-col min-h-screen">
      <Nav />
      <Hero />

      <main className="max-w-6xl mx-auto px-6 md:px-12 py-10 md:py-16 w-full box-border">
        <Gallery />

        <section className="max-w-2xl mb-16">
          <p className="text-lg leading-relaxed text-ink2-800">
            Are you ready to transform your health and achieve your weight loss goals? Join
            Sahara Management&rsquo;s exclusive weight loss trials and take the first step
            towards a healthier, more confident you! Our scientifically-backed programs are
            designed to help you lose weight effectively and safely, under the guidance of our
            expert healthcare team. By participating in our trials, you&rsquo;ll gain access to
            personalized support, innovative strategies, and cutting-edge tools that will
            empower you on your weight loss journey. Don&rsquo;t wait—be part of something that
            could change your life!
          </p>
        </section>

        <hr className="border-t-2 border-divider mb-12" />

        <ScreeningForm trial="weight-loss" />
      </main>

      <Footer />
    </div>
  );
}
