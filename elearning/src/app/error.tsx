"use client";

import { Button } from "@/components/ui/button";

export default function Error({ reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return (
    <main className="flex flex-1 flex-col items-center justify-center gap-4 px-4 py-20 text-center">
      <h1 className="text-xl font-semibold">Une erreur est survenue</h1>
      <p className="max-w-sm text-muted-foreground">Réessayez dans un instant. Si le problème persiste, contactez le support.</p>
      <Button onClick={reset}>Réessayer</Button>
    </main>
  );
}
