import { ChevronDown } from "lucide-react";

const items = [
  { q: "Comment payer ?", a: "Par carte CIB ou Edahabia (paiement sécurisé Chargily), ou par carte internationale Visa/Mastercard. L'accès est activé automatiquement après le paiement." },
  { q: "Combien de temps ai-je accès aux cours ?", a: "L'accès est à vie : vous gardez le cours et toutes ses mises à jour." },
  { q: "Les vidéos fonctionnent-elles sur mobile ?", a: "Oui. Le lecteur s'adapte à votre connexion, même en 4G, sur téléphone, tablette ou ordinateur." },
  { q: "Est-ce que je reçois un certificat ?", a: "Oui, après avoir terminé le cours et réussi le quiz final. Chaque certificat a un code vérifiable en ligne." },
  { q: "Puis-je partager mon compte ?", a: "Non. Un compte est personnel et le nombre de connexions simultanées est limité." },
];

export function Faq() {
  return (
    <div className="divide-y divide-border rounded-xl border border-border bg-card">
      {items.map((item) => (
        <details key={item.q} className="group px-5">
          <summary className="flex cursor-pointer items-center justify-between gap-4 py-4 font-medium">
            {item.q}
            <ChevronDown className="size-4 shrink-0 transition group-open:rotate-180" aria-hidden />
          </summary>
          <p className="pb-4 text-sm text-muted-foreground">{item.a}</p>
        </details>
      ))}
    </div>
  );
}
