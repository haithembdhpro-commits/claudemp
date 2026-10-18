import { ButtonLink } from "@/components/ui/button";
import { Logo } from "@/components/logo";

export default function NotFound() {
  return (
    <main className="flex flex-1 flex-col items-center justify-center gap-6 px-4 py-20 text-center">
      <Logo />
      <p className="text-6xl font-bold text-primary">404</p>
      <h1 className="text-xl font-semibold">Page introuvable</h1>
      <p className="max-w-sm text-muted-foreground">La page que vous cherchez n&apos;existe pas ou a été déplacée.</p>
      <ButtonLink href="/">Retour à l&apos;accueil</ButtonLink>
    </main>
  );
}
