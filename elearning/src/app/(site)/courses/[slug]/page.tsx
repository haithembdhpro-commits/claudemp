import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Award, CheckCircle2, Clock, PlayCircle, ShieldCheck, Smartphone, Star, Users } from "lucide-react";
import { CourseCover } from "@/components/course-card";
import { Curriculum } from "@/components/curriculum";
import { Badge } from "@/components/ui/badge";
import { Button, ButtonLink } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Container } from "@/components/ui/container";
import { getCourseBySlug, getCourses, isEnrolled } from "@/lib/data";
import { courseDuration, courseLessons, formatDuration, formatDzd, formatEur, levelLabel } from "@/lib/utils";

export function generateStaticParams() {
  return getCourses().map((c) => ({ slug: c.slug }));
}

export async function generateMetadata({ params }: PageProps<"/courses/[slug]">): Promise<Metadata> {
  const course = getCourseBySlug((await params).slug);
  if (!course) return {};
  return { title: course.title, description: course.subtitle, openGraph: { title: course.title, description: course.subtitle } };
}

export default async function CoursePage({ params }: PageProps<"/courses/[slug]">) {
  const course = getCourseBySlug((await params).slug);
  if (!course) notFound();

  const lessons = courseLessons(course);
  const firstPreview = lessons.find((l) => l.isPreview);
  const enrolled = isEnrolled(course.id);
  const firstLesson = lessons[0];

  return (
    <>
      <section className="bg-primary text-primary-foreground">
        <Container className="py-12 lg:py-16">
          <div className="max-w-2xl space-y-4">
            <div className="flex flex-wrap items-center gap-2">
              <Badge className="bg-white/15 text-white">{course.category}</Badge>
              <Badge className="bg-white/15 text-white">{levelLabel[course.level]}</Badge>
            </div>
            <h1 className="text-3xl font-bold sm:text-4xl">{course.title}</h1>
            <p className="text-lg text-primary-foreground/80">{course.subtitle}</p>
            <div className="flex flex-wrap gap-x-5 gap-y-2 text-sm text-primary-foreground/80">
              <span className="flex items-center gap-1"><Star className="size-4 fill-amber-400 text-amber-400" aria-hidden /> {course.rating}/5</span>
              <span className="flex items-center gap-1"><Users className="size-4" aria-hidden /> {course.students.toLocaleString("fr-FR")} élèves</span>
              <span className="flex items-center gap-1"><Clock className="size-4" aria-hidden /> {formatDuration(courseDuration(course))}</span>
              <span>{lessons.length} leçons</span>
            </div>
          </div>
        </Container>
      </section>

      <Container className="grid gap-10 py-12 lg:grid-cols-[1fr_360px]">
        <div className="space-y-10">
          <section>
            <h2 className="text-xl font-bold">Ce que vous allez apprendre</h2>
            <ul className="mt-4 grid gap-3 sm:grid-cols-2">
              {course.outcomes.map((o) => (
                <li key={o} className="flex gap-2 text-sm">
                  <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-accent" aria-hidden /> {o}
                </li>
              ))}
            </ul>
          </section>
          <section>
            <h2 className="text-xl font-bold">Programme</h2>
            <p className="mt-1 text-sm text-muted-foreground">
              {course.modules.length} modules · {lessons.length} leçons · {formatDuration(courseDuration(course))}
            </p>
            <div className="mt-4">
              <Curriculum course={course} />
            </div>
          </section>
          <section>
            <h2 className="text-xl font-bold">Description</h2>
            <p className="mt-3 leading-relaxed text-muted-foreground">{course.description}</p>
            <p className="mt-3 text-sm text-muted-foreground">Formateur : {course.instructor}</p>
          </section>
        </div>

        <aside className="lg:sticky lg:top-24 lg:self-start">
          <Card className="overflow-hidden">
            <CourseCover course={course} className="h-40" />
            <div className="space-y-4 p-6">
              <div>
                <p className="text-3xl font-bold">{formatDzd(course.priceDzd)}</p>
                <p className="text-sm text-muted-foreground">ou {formatEur(course.priceEur)} par carte internationale</p>
              </div>
              {enrolled ? (
                <ButtonLink href={`/learn/${course.slug}/${firstLesson.slug}`} variant="accent" size="lg" className="w-full">
                  <PlayCircle className="size-5" aria-hidden /> Continuer le cours
                </ButtonLink>
              ) : (
                <Button size="lg" className="w-full" disabled title="Paiement bientôt disponible">
                  Acheter la formation
                </Button>
              )}
              {firstPreview && (
                <ButtonLink href={`/learn/${course.slug}/${firstPreview.slug}`} variant="outline" className="w-full">
                  Voir une leçon gratuite
                </ButtonLink>
              )}
              <ul className="space-y-2 border-t border-border pt-4 text-sm text-muted-foreground">
                <li className="flex items-center gap-2"><ShieldCheck className="size-4 text-accent" aria-hidden /> Paiement sécurisé CIB, Edahabia, Visa</li>
                <li className="flex items-center gap-2"><Smartphone className="size-4 text-accent" aria-hidden /> Accès à vie, sur tous vos appareils</li>
                <li className="flex items-center gap-2"><Award className="size-4 text-accent" aria-hidden /> Certificat de réussite</li>
              </ul>
            </div>
          </Card>
        </aside>
      </Container>
    </>
  );
}
