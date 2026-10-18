import { Award, CreditCard, Quote, ShieldCheck, Smartphone, Star, Video } from "lucide-react";
import { CourseCard } from "@/components/course-card";
import { Faq } from "@/components/faq";
import { ButtonLink } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Container } from "@/components/ui/container";
import { getCourses, getTestimonials } from "@/lib/data";

const benefits = [
  { icon: Video, title: "Vidéos claires", text: "Des leçons courtes, en français, avec des exemples sur de vrais graphiques." },
  { icon: Smartphone, title: "Partout, même en 4G", text: "Lecteur adaptatif sur téléphone, tablette et ordinateur." },
  { icon: CreditCard, title: "Paiement local", text: "CIB, Edahabia ou carte internationale. Accès immédiat." },
  { icon: Award, title: "Certificat vérifiable", text: "Un certificat avec code unique à la fin de chaque formation." },
];

export default function HomePage() {
  const courses = getCourses();
  const testimonials = getTestimonials();

  return (
    <>
      <section className="relative overflow-hidden bg-primary text-primary-foreground">
        <div className="pointer-events-none absolute -end-24 -top-24 size-96 rounded-full bg-accent/20 blur-3xl" aria-hidden />
        <Container className="relative grid items-center gap-10 py-16 md:grid-cols-2 md:py-24">
          <div className="space-y-6">
            <span className="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-medium">
              <Star className="size-3.5 fill-amber-400 text-amber-400" aria-hidden /> 4,8/5 · plus de 2 600 élèves
            </span>
            <h1 className="text-4xl font-bold leading-tight tracking-tight sm:text-5xl">
              Apprenez le trading <span className="text-accent">avec méthode</span>, pas au hasard.
            </h1>
            <p className="max-w-lg text-lg text-primary-foreground/80">
              Des formations structurées, des quiz pour valider vos acquis et un certificat à la clé. Commencez gratuitement avec les leçons d&apos;aperçu.
            </p>
            <div className="flex flex-col gap-3 sm:flex-row">
              <ButtonLink href="/courses" variant="accent" size="lg">Voir les formations</ButtonLink>
              <ButtonLink href="/signup" size="lg" className="border border-white/25 bg-transparent hover:bg-white/10">
                Créer un compte gratuit
              </ButtonLink>
            </div>
          </div>
          <Card className="hidden border-white/10 bg-white/5 p-6 text-primary-foreground md:block">
            <svg viewBox="0 0 320 180" className="w-full" aria-hidden>
              {[30, 70, 110, 150].map((y) => (
                <line key={y} x1="0" x2="320" y1={y} y2={y} stroke="currentColor" strokeOpacity="0.1" />
              ))}
              {[
                [20, 140, 120, 105, 150], [50, 120, 105, 92, 126], [80, 104, 112, 96, 118], [110, 112, 88, 80, 116],
                [140, 88, 74, 66, 94], [170, 74, 82, 70, 88], [200, 82, 58, 50, 86], [230, 58, 46, 38, 64],
                [260, 46, 52, 40, 58], [290, 52, 26, 18, 56],
              ].map(([x, open, close, high, low], i) => {
                const up = close < open;
                return (
                  <g key={i} className={up ? "text-accent" : "text-red-400"}>
                    <line x1={x} x2={x} y1={high} y2={low} stroke="currentColor" strokeWidth="2" />
                    <rect x={x - 7} y={Math.min(open, close)} width="14" height={Math.max(4, Math.abs(close - open))} fill="currentColor" rx="2" />
                  </g>
                );
              })}
            </svg>
            <div className="mt-4 grid grid-cols-3 gap-3 text-center text-sm">
              <div><p className="text-2xl font-bold">3</p><p className="text-primary-foreground/70">formations</p></div>
              <div><p className="text-2xl font-bold">30+</p><p className="text-primary-foreground/70">leçons</p></div>
              <div><p className="text-2xl font-bold">64 %</p><p className="text-primary-foreground/70">vont au bout</p></div>
            </div>
          </Card>
        </Container>
      </section>

      <section id="avantages" className="scroll-mt-20 py-16">
        <Container>
          <h2 className="text-2xl font-bold sm:text-3xl">Pourquoi Tradeversity ?</h2>
          <div className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {benefits.map(({ icon: Icon, title, text }) => (
              <Card key={title} className="p-5">
                <span className="grid size-10 place-items-center rounded-xl bg-accent/15 text-emerald-700">
                  <Icon className="size-5" aria-hidden />
                </span>
                <h3 className="mt-4 font-semibold">{title}</h3>
                <p className="mt-1 text-sm text-muted-foreground">{text}</p>
              </Card>
            ))}
          </div>
        </Container>
      </section>

      <section className="bg-card py-16">
        <Container>
          <div className="flex flex-wrap items-end justify-between gap-4">
            <div>
              <h2 className="text-2xl font-bold sm:text-3xl">Nos formations</h2>
              <p className="mt-2 text-muted-foreground">Du premier ordre à la gestion du risque avancée.</p>
            </div>
            <ButtonLink href="/courses" variant="outline">Tout voir</ButtonLink>
          </div>
          <div className="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            {courses.map((c) => (
              <CourseCard key={c.id} course={c} />
            ))}
          </div>
        </Container>
      </section>

      <section className="py-16">
        <Container>
          <h2 className="text-2xl font-bold sm:text-3xl">Ils se sont formés avec nous</h2>
          <div className="mt-8 grid gap-4 md:grid-cols-3">
            {testimonials.map((t) => (
              <Card key={t.name} className="p-6">
                <Quote className="size-6 text-accent" aria-hidden />
                <p className="mt-3 text-sm">{t.text}</p>
                <p className="mt-4 text-sm font-semibold">
                  {t.name} <span className="font-normal text-muted-foreground">· {t.city}</span>
                </p>
              </Card>
            ))}
          </div>
        </Container>
      </section>

      <section id="faq" className="scroll-mt-20 bg-card py-16">
        <Container className="max-w-3xl">
          <h2 className="text-2xl font-bold sm:text-3xl">Questions fréquentes</h2>
          <div className="mt-8">
            <Faq />
          </div>
        </Container>
      </section>

      <section className="py-16">
        <Container>
          <Card className="flex flex-col items-start gap-4 bg-primary p-8 text-primary-foreground md:flex-row md:items-center md:justify-between">
            <div className="flex items-start gap-3">
              <ShieldCheck className="mt-1 size-6 shrink-0 text-accent" aria-hidden />
              <div>
                <h2 className="text-xl font-bold">Prêt à trader avec un plan ?</h2>
                <p className="text-primary-foreground/80">Commencez par les leçons gratuites, sans carte bancaire.</p>
              </div>
            </div>
            <ButtonLink href="/signup" variant="accent" size="lg">Créer mon compte</ButtonLink>
          </Card>
        </Container>
      </section>
    </>
  );
}
