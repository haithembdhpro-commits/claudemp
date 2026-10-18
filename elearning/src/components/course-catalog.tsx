"use client";

import { useMemo, useState } from "react";
import { Search } from "lucide-react";
import type { Course, Level } from "@/lib/types";
import { cn, levelLabel } from "@/lib/utils";
import { CourseCard } from "./course-card";
import { Input } from "./ui/input";

const levels: (Level | "all")[] = ["all", "debutant", "intermediaire", "avance"];

export function CourseCatalog({ courses }: { courses: Course[] }) {
  const [level, setLevel] = useState<Level | "all">("all");
  const [query, setQuery] = useState("");

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    return courses.filter(
      (c) =>
        (level === "all" || c.level === level) &&
        (!q || `${c.title} ${c.subtitle} ${c.category}`.toLowerCase().includes(q)),
    );
  }, [courses, level, query]);

  return (
    <>
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex flex-wrap gap-2" role="group" aria-label="Filtrer par niveau">
          {levels.map((l) => (
            <button
              key={l}
              type="button"
              onClick={() => setLevel(l)}
              aria-pressed={level === l}
              className={cn(
                "rounded-full border px-4 py-1.5 text-sm font-medium transition",
                level === l ? "border-primary bg-primary text-primary-foreground" : "border-border bg-card hover:bg-muted",
              )}
            >
              {l === "all" ? "Tous" : levelLabel[l]}
            </button>
          ))}
        </div>
        <div className="relative sm:w-72">
          <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
          <Input
            type="search"
            placeholder="Rechercher une formation"
            aria-label="Rechercher une formation"
            className="ps-9"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
          />
        </div>
      </div>
      {filtered.length > 0 ? (
        <div className="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          {filtered.map((c) => (
            <CourseCard key={c.id} course={c} />
          ))}
        </div>
      ) : (
        <p className="mt-12 text-center text-muted-foreground">Aucune formation ne correspond à votre recherche.</p>
      )}
    </>
  );
}
