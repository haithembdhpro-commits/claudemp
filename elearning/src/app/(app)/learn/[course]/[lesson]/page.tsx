import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { ChevronLeft, ChevronRight, Lock } from "lucide-react";
import { LessonSidebar } from "@/components/lesson-sidebar";
import { MarkComplete } from "@/components/mark-complete";
import { ButtonLink } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Progress } from "@/components/ui/progress";
import { VideoPlayer } from "@/components/video-player";
import { getCompletedLessonIds, getCourseProgress, getCourses, getLesson, isEnrolled } from "@/lib/data";
import { courseLessons, formatDuration, formatDzd } from "@/lib/utils";

export function generateStaticParams() {
  return getCourses().flatMap((course) => courseLessons(course).map((l) => ({ course: course.slug, lesson: l.slug })));
}

export async function generateMetadata({ params }: PageProps<"/learn/[course]/[lesson]">): Promise<Metadata> {
  const { course, lesson } = await params;
  const found = getLesson(course, lesson);
  return found ? { title: `${found.lesson.title} — ${found.course.title}`, robots: { index: false } } : {};
}

export default async function LessonPage({ params }: PageProps<"/learn/[course]/[lesson]">) {
  const { course: courseSlug, lesson: lessonSlug } = await params;
  const found = getLesson(courseSlug, lessonSlug);
  if (!found) notFound();

  const { course, lesson, prev, next } = found;
  const enrolled = isEnrolled(course.id);
  const canWatch = enrolled || lesson.isPreview;
  const completed = getCompletedLessonIds();
  const progress = getCourseProgress(course);

  return (
    <div className="mx-auto grid w-full max-w-7xl gap-6 px-4 py-6 sm:px-6 lg:grid-cols-[1fr_320px]">
      <div className="min-w-0 space-y-5">
        <Link href={enrolled ? "/dashboard" : `/courses/${course.slug}`} className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
          <ChevronLeft className="size-4" aria-hidden /> {course.title}
        </Link>

        {canWatch ? (
          <VideoPlayer title={lesson.title} />
        ) : (
          <Card className="flex aspect-video flex-col items-center justify-center gap-4 p-6 text-center">
            <Lock className="size-10 text-muted-foreground" aria-hidden />
            <p className="max-w-sm text-muted-foreground">Cette leçon fait partie de la formation complète.</p>
            <ButtonLink href={`/courses/${course.slug}`}>Débloquer pour {formatDzd(course.priceDzd)}</ButtonLink>
          </Card>
        )}

        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <h1 className="text-xl font-bold sm:text-2xl">{lesson.title}</h1>
            <p className="mt-1 text-sm text-muted-foreground">{formatDuration(lesson.durationMin)}</p>
          </div>
          {enrolled && <MarkComplete initiallyDone={completed.has(lesson.id)} />}
        </div>

        {canWatch && <p className="leading-relaxed text-muted-foreground">{lesson.summary}</p>}

        <div className="flex items-center justify-between gap-3 border-t border-border pt-5">
          {prev ? (
            <ButtonLink href={`/learn/${course.slug}/${prev.slug}`} variant="outline" size="sm">
              <ChevronLeft className="size-4" aria-hidden /> Précédente
            </ButtonLink>
          ) : <span />}
          {next ? (
            <ButtonLink href={`/learn/${course.slug}/${next.slug}`} size="sm">
              Suivante <ChevronRight className="size-4" aria-hidden />
            </ButtonLink>
          ) : course.quizId && enrolled ? (
            <ButtonLink href={`/learn/${course.slug}/quiz/${course.quizId}`} variant="accent" size="sm">Passer le quiz final</ButtonLink>
          ) : <span />}
        </div>
      </div>

      <aside className="lg:sticky lg:top-20 lg:max-h-[calc(100vh-6rem)] lg:self-start lg:overflow-y-auto">
        <Card className="p-4">
          {enrolled && (
            <div className="mb-5 space-y-2 border-b border-border pb-4">
              <p className="text-sm font-medium">Progression : {progress.percent} %</p>
              <Progress value={progress.percent} />
            </div>
          )}
          <LessonSidebar course={course} currentLessonId={lesson.id} completed={completed} enrolled={enrolled} />
        </Card>
      </aside>
    </div>
  );
}
