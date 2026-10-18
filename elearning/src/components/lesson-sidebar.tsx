import Link from "next/link";
import { CheckCircle2, Circle, CirclePlay, ClipboardCheck, Lock } from "lucide-react";
import type { Course } from "@/lib/types";
import { cn, formatDuration } from "@/lib/utils";

export function LessonSidebar({
  course,
  currentLessonId,
  completed,
  enrolled,
}: {
  course: Course;
  currentLessonId?: string;
  completed: Set<string>;
  enrolled: boolean;
}) {
  return (
    <nav aria-label="Leçons du cours" className="space-y-5">
      {course.modules.map((m) => (
        <div key={m.id}>
          <p className="mb-2 px-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            {m.position}. {m.title}
          </p>
          <ul className="space-y-0.5">
            {m.lessons.map((l) => {
              const active = l.id === currentLessonId;
              const locked = !enrolled && !l.isPreview;
              const Icon = completed.has(l.id) ? CheckCircle2 : locked ? Lock : active ? CirclePlay : Circle;
              return (
                <li key={l.id}>
                  <Link
                    href={`/learn/${course.slug}/${l.slug}`}
                    aria-current={active ? "page" : undefined}
                    className={cn(
                      "flex items-start gap-2 rounded-lg px-2 py-2 text-sm hover:bg-muted",
                      active && "bg-primary/10 font-medium text-primary",
                      locked && "text-muted-foreground",
                    )}
                  >
                    <Icon className={cn("mt-0.5 size-4 shrink-0", completed.has(l.id) && "text-accent")} aria-hidden />
                    <span className="flex-1">{l.title}</span>
                    <span className="shrink-0 text-xs text-muted-foreground">{formatDuration(l.durationMin)}</span>
                  </Link>
                </li>
              );
            })}
          </ul>
        </div>
      ))}
      {course.quizId && enrolled && (
        <Link
          href={`/learn/${course.slug}/quiz/${course.quizId}`}
          className="flex items-center gap-2 rounded-lg border border-border px-3 py-2.5 text-sm font-medium hover:bg-muted"
        >
          <ClipboardCheck className="size-4 text-primary" aria-hidden /> Quiz final
        </Link>
      )}
    </nav>
  );
}
