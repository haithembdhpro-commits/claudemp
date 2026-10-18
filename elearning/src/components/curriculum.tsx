import Link from "next/link";
import { ChevronDown, CirclePlay, Lock } from "lucide-react";
import type { Course } from "@/lib/types";
import { formatDuration } from "@/lib/utils";

export function Curriculum({ course }: { course: Course }) {
  return (
    <div className="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card">
      {course.modules.map((m, i) => (
        <details key={m.id} className="group" open={i === 0}>
          <summary className="flex cursor-pointer items-center justify-between gap-3 px-4 py-4 hover:bg-muted/60">
            <span className="font-medium">
              <span className="me-2 text-muted-foreground">{String(m.position).padStart(2, "0")}</span>
              {m.title}
            </span>
            <span className="flex items-center gap-3 text-xs text-muted-foreground">
              {m.lessons.length} leçons
              <ChevronDown className="size-4 transition group-open:rotate-180" aria-hidden />
            </span>
          </summary>
          <ul className="border-t border-border bg-muted/30">
            {m.lessons.map((l) => (
              <li key={l.id} className="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                <span className="flex items-center gap-2">
                  {l.isPreview ? (
                    <CirclePlay className="size-4 text-accent" aria-hidden />
                  ) : (
                    <Lock className="size-4 text-muted-foreground" aria-hidden />
                  )}
                  {l.isPreview ? (
                    <Link href={`/learn/${course.slug}/${l.slug}`} className="text-primary underline-offset-2 hover:underline">
                      {l.title}
                    </Link>
                  ) : (
                    l.title
                  )}
                  {l.isPreview && <span className="text-xs font-medium text-emerald-700">Aperçu gratuit</span>}
                </span>
                <span className="shrink-0 text-xs text-muted-foreground">{formatDuration(l.durationMin)}</span>
              </li>
            ))}
          </ul>
        </details>
      ))}
    </div>
  );
}
