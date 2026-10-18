import Link from "next/link";
import { Logo } from "./logo";
import { Container } from "./ui/container";

export function SiteFooter() {
  return (
    <footer className="mt-auto border-t border-border bg-card">
      <Container className="grid gap-8 py-10 sm:grid-cols-3">
        <div className="space-y-3">
          <Logo />
          <p className="max-w-xs text-sm text-muted-foreground">
            Formations de trading en français, pensées pour l&apos;Algérie et le Maghreb.
          </p>
        </div>
        <div>
          <p className="mb-3 text-sm font-semibold">Plateforme</p>
          <ul className="space-y-2 text-sm text-muted-foreground">
            <li><Link className="hover:text-foreground" href="/courses">Formations</Link></li>
            <li><Link className="hover:text-foreground" href="/dashboard">Mon espace</Link></li>
            <li><Link className="hover:text-foreground" href="/certificate/TV-2026-0001">Vérifier un certificat</Link></li>
          </ul>
        </div>
        <div>
          <p className="mb-3 text-sm font-semibold">Légal</p>
          <ul className="space-y-2 text-sm text-muted-foreground">
            <li><Link className="hover:text-foreground" href="/legal/cgu">Conditions d&apos;utilisation</Link></li>
            <li><Link className="hover:text-foreground" href="/legal/cgv">Conditions de vente</Link></li>
            <li><Link className="hover:text-foreground" href="/legal/confidentialite">Confidentialité</Link></li>
          </ul>
        </div>
      </Container>
      <Container className="border-t border-border py-5 text-xs text-muted-foreground">
        © 2026 Tradeversity. Le trading comporte un risque de perte en capital.
      </Container>
    </footer>
  );
}
