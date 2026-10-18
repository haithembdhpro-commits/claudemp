"use client";

import { useState } from "react";
import Link from "next/link";
import { Award, RotateCcw } from "lucide-react";
import type { Quiz } from "@/lib/types";
import { cn } from "@/lib/utils";
import { Button, ButtonLink } from "./ui/button";
import { Card } from "./ui/card";

// Démo : la correction se fait dans le navigateur.
// En production, les réponses seront corrigées côté serveur (quiz_attempts) sans exposer les bonnes réponses.
export function QuizRunner({ quiz, courseSlug }: { quiz: Quiz; courseSlug: string }) {
  const [answers, setAnswers] = useState<Record<string, number>>({});
  const [submitted, setSubmitted] = useState(false);

  const correct = quiz.questions.filter((q) => answers[q.id] === q.answer).length;
  const score = Math.round((correct / quiz.questions.length) * 100);
  const passed = score >= quiz.passScore;
  const allAnswered = quiz.questions.every((q) => answers[q.id] !== undefined);

  return (
    <div className="space-y-5">
      {submitted && (
        <Card className={cn("flex flex-col gap-4 p-6 sm:flex-row sm:items-center sm:justify-between", passed ? "border-accent bg-accent/10" : "border-danger/40 bg-red-50")}>
          <div>
            <p className="text-2xl font-bold">{score} %</p>
            <p className="text-sm text-muted-foreground">
              {correct}/{quiz.questions.length} bonnes réponses · minimum requis {quiz.passScore} %
            </p>
          </div>
          {passed ? (
            <ButtonLink href="/certificate/TV-2026-0001" variant="accent"><Award className="size-4" aria-hidden /> Voir mon certificat</ButtonLink>
          ) : (
            <Button variant="outline" onClick={() => { setAnswers({}); setSubmitted(false); }}>
              <RotateCcw className="size-4" aria-hidden /> Réessayer
            </Button>
          )}
        </Card>
      )}

      {quiz.questions.map((q, i) => (
        <Card key={q.id} className="p-5">
          <fieldset>
            <legend className="font-semibold">{i + 1}. {q.prompt}</legend>
            <div className="mt-4 grid gap-2">
              {q.choices.map((choice, ci) => {
                const selected = answers[q.id] === ci;
                const showRight = submitted && ci === q.answer;
                const showWrong = submitted && selected && ci !== q.answer;
                return (
                  <label
                    key={choice}
                    className={cn(
                      "flex cursor-pointer items-center gap-3 rounded-xl border border-border px-4 py-3 text-sm transition hover:bg-muted",
                      selected && !submitted && "border-primary bg-primary/5",
                      showRight && "border-accent bg-accent/10",
                      showWrong && "border-danger bg-red-50",
                      submitted && "cursor-default",
                    )}
                  >
                    <input
                      type="radio"
                      name={q.id}
                      className="accent-[var(--primary)]"
                      checked={selected}
                      disabled={submitted}
                      onChange={() => setAnswers((a) => ({ ...a, [q.id]: ci }))}
                    />
                    {choice}
                  </label>
                );
              })}
            </div>
          </fieldset>
        </Card>
      ))}

      {!submitted && (
        <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-between">
          <Link href={`/courses/${courseSlug}`} className="text-center text-sm text-muted-foreground hover:text-foreground sm:self-center">Retour au cours</Link>
          <Button size="lg" disabled={!allAnswered} onClick={() => setSubmitted(true)}>Valider mes réponses</Button>
        </div>
      )}
    </div>
  );
}
