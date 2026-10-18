import type { Metadata } from "next";
import Link from "next/link";
import { Award, BookOpen, CheckCircle2, PlayCircle } from "lucide-react";
import { CourseCover } from "@/components/course-card";
import { Badge } from "@/components/ui/badge";
import { ButtonLink } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Container } from "@/components/ui/container";
import { Progress } from "@/components/ui/progress";
import { getCurrentUser, getMyCourses } from "@/lib/data";

export const metadata: Metadata = { title: "Mon espace" };

export default function DashboardPage() {
  const user = getCurrentUser();
  const myCourses = getMyCourses();
  const finished = myCourses.filter((c) => c.percent === 100).length;
  const lessonsDone = myCourses.reduce((s, c) => s + c.completed, 0);
  const current = myCourses.find((c) => c.percent < 100) ?? myCourses[0];

  return (
    <Container className="max-w-7xl py-10">
      <h1 className="text-2xl font-bold sm:text-3xl">Bonjour {user.fullName.split(" ")[0]} 👋</h1>
      <p className="mt-1 text-muted-foreground">Reprenez là où vous vous êtes arrêté.</p>

      <div className="mt-8 grid grid-cols-3 gap-3 sm:gap-4">
        {[
          { icon: BookOpen, label: "Formations", value: myCourses.length },
          { icon: CheckCircle2, label: "Leçons terminées", value: lessonsDone },
          { icon: Award, label: "Certificats", value: finished },
        ].map(({ icon: Icon, label, value }) => (
          <Card key={label} className="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:gap-4 sm:p-5">
            <span className="grid size-9 place-items-center rounded-xl bg-primary/10 text-primary sm:size-11"><Icon className="size-5" aria-hidden /></span>
            <div>
              <p className="text-xl font-bold sm:text-2xl">{value}</p>
              <p className="text-xs text-muted-foreground sm:text-sm">{label}</p>
            </div>
          </Card>
        ))}
      </div>

      {current && (
        <Card className="mt-8 flex flex-col gap-4 bg-primary p-6 text-primary-foreground sm:flex-row sm:items-center sm:justify-between">
          <div className="min-w-0">
            <p className="text-sm text-primary-foreground/70">Continuer</p>
            <p className="truncate text-lg font-semibold">{current.course.title}</p>
            <p className="truncate text-sm text-primary-foreground/80">Prochaine leçon : {current.nextLesson.title}</p>
          </div>
          <ButtonLink href={`/learn/${current.course.slug}/${current.nextLesson.slug}`} variant="accent" className="shrink-0">
            <PlayCircle className="size-4" aria-hidden /> Reprendre
          </ButtonLink>
        </Card>
      )}

      <h2 className="mt-10 text-xl font-bold">Mes formations</h2>
      <div className="mt-4 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        {myCourses.map(({ course, percent, completed, total, nextLesson }) => (
          <Card key={course.id} className="flex flex-col overflow-hidden">
            <CourseCover course={course} className="h-28" />
            <div className="flex flex-1 flex-col gap-3 p-5">
              <div className="flex items-start justify-between gap-2">
                <h3 className="font-semibold">{course.title}</h3>
                {percent === 100 && <Badge tone="accent">Terminé</Badge>}
              </div>
              <Progress value={percent} />
              <p className="text-xs text-muted-foreground">{completed}/{total} leçons · {percent} %</p>
              <div className="mt-auto flex flex-wrap gap-2 pt-2">
                <ButtonLink href={`/learn/${course.slug}/${nextLesson.slug}`} size="sm">
                  {percent === 100 ? "Revoir" : "Continuer"}
                </ButtonLink>
                {course.quizId && (
                  <ButtonLink href={`/learn/${course.slug}/quiz/${course.quizId}`} size="sm" variant="outline">Quiz final</ButtonLink>
                )}
                {percent === 100 && (
                  <Link href="/certificate/TV-2026-0001" className="inline-flex h-9 items-center px-2 text-sm font-medium text-primary hover:underline">
                    Certificat
                  </Link>
                )}
              </div>
            </div>
          </Card>
        ))}
      </div>
    </Container>
  );
}
