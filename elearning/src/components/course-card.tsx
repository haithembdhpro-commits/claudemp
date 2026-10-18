import Link from "next/link";
import { Clock, Star, Users } from "lucide-react";
import type { Course } from "@/lib/types";
import { courseDuration, courseLessons, formatDuration, formatDzd, levelLabel } from "@/lib/utils";
import { Badge } from "./ui/badge";
import { Card } from "./ui/card";

export function CourseCover({ course, className = "" }: { course: Course; className?: string }) {
  return (
    <div className={`relative overflow-hidden bg-gradient-to-br from-primary to-primary/70 ${className}`} aria-hidden>
      <svg viewBox="0 0 200 80" className="absolute inset-x-0 bottom-0 h-2/3 w-full text-accent/70" preserveAspectRatio="none">
        <polyline
          fill="none"
          stroke="currentColor"
          strokeWidth="2.5"
          points={course.id === "c2" ? "0,70 30,55 55,62 85,35 110,45 140,20 170,28 200,8" : course.id === "c3" ? "0,40 25,48 50,30 80,52 110,38 140,44 170,22 200,26" : "0,65 35,58 60,45 90,50 120,30 150,34 180,18 200,12"}
        />
      </svg>
      <span className="absolute start-4 top-4 rounded-full bg-white/15 px-3 py-1 text-xs font-medium text-white">{course.category}</span>
    </div>
  );
}

export function CourseCard({ course }: { course: Course }) {
  return (
    <Card className="group flex flex-col overflow-hidden transition hover:-translate-y-0.5 hover:shadow-md">
      <Link href={`/courses/${course.slug}`} className="flex flex-1 flex-col">
        <CourseCover course={course} className="h-36" />
        <div className="flex flex-1 flex-col gap-3 p-5">
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone="primary">{levelLabel[course.level]}</Badge>
            <span className="flex items-center gap-1 text-xs text-muted-foreground">
              <Star className="size-3.5 fill-amber-400 text-amber-400" aria-hidden /> {course.rating}
            </span>
          </div>
          <h3 className="text-lg font-semibold leading-snug group-hover:text-primary">{course.title}</h3>
          <p className="line-clamp-2 text-sm text-muted-foreground">{course.subtitle}</p>
          <div className="mt-auto flex items-center gap-4 pt-2 text-xs text-muted-foreground">
            <span className="flex items-center gap-1"><Clock className="size-3.5" aria-hidden /> {formatDuration(courseDuration(course))}</span>
            <span>{courseLessons(course).length} leçons</span>
            <span className="flex items-center gap-1"><Users className="size-3.5" aria-hidden /> {course.students.toLocaleString("fr-FR")}</span>
          </div>
          <p className="text-lg font-bold">{formatDzd(course.priceDzd)}</p>
        </div>
      </Link>
    </Card>
  );
}
