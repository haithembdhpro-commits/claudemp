import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { ChevronLeft } from "lucide-react";
import { QuizRunner } from "@/components/quiz-runner";
import { Container } from "@/components/ui/container";
import { getCourses, getQuiz } from "@/lib/data";

export function generateStaticParams() {
  return getCourses()
    .filter((c) => c.quizId)
    .map((c) => ({ course: c.slug, quiz: c.quizId as string }));
}

export const metadata: Metadata = { title: "Quiz", robots: { index: false } };

export default async function QuizPage({ params }: PageProps<"/learn/[course]/quiz/[quiz]">) {
  const { course: courseSlug, quiz: quizId } = await params;
  const found = getQuiz(courseSlug, quizId);
  if (!found) notFound();

  return (
    <Container className="max-w-3xl py-8">
      <Link href="/dashboard" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
        <ChevronLeft className="size-4" aria-hidden /> Mes cours
      </Link>
      <h1 className="mt-4 text-2xl font-bold">{found.quiz.title}</h1>
      <p className="mt-1 text-sm text-muted-foreground">
        {found.quiz.questions.length} questions · réussite à partir de {found.quiz.passScore} %
      </p>
      <div className="mt-6">
        <QuizRunner quiz={found.quiz} courseSlug={found.course.slug} />
      </div>
    </Container>
  );
}
