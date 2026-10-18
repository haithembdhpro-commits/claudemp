import type { Metadata } from "next";
import { CourseCatalog } from "@/components/course-catalog";
import { Container } from "@/components/ui/container";
import { getCourses } from "@/lib/data";

export const metadata: Metadata = {
  title: "Formations",
  description: "Toutes les formations de trading Tradeversity : débutant, intermédiaire et avancé.",
};

export default function CoursesPage() {
  return (
    <Container className="py-12">
      <h1 className="text-3xl font-bold">Formations</h1>
      <p className="mt-2 text-muted-foreground">Choisissez votre niveau et avancez à votre rythme.</p>
      <div className="mt-8">
        <CourseCatalog courses={getCourses()} />
      </div>
    </Container>
  );
}
