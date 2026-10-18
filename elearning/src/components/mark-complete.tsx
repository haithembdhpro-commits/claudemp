"use client";

import { useState } from "react";
import { CheckCircle2 } from "lucide-react";
import { Button } from "./ui/button";

// Démo : l'état n'est pas sauvegardé. En production, il sera écrit dans lesson_progress.
export function MarkComplete({ initiallyDone }: { initiallyDone: boolean }) {
  const [done, setDone] = useState(initiallyDone);
  return (
    <Button variant={done ? "outline" : "accent"} onClick={() => setDone((d) => !d)} aria-pressed={done}>
      <CheckCircle2 className="size-4" aria-hidden />
      {done ? "Leçon terminée" : "Marquer comme terminée"}
    </Button>
  );
}
