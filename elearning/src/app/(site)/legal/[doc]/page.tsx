import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Container } from "@/components/ui/container";

const docs = {
  cgu: {
    title: "Conditions générales d'utilisation",
    sections: [
      ["Objet", "Les présentes conditions encadrent l'utilisation de la plateforme Tradeversity et de ses contenus de formation."],
      ["Compte personnel", "Chaque compte est strictement personnel. Le partage d'identifiants est interdit et peut entraîner la suspension de l'accès sans remboursement."],
      ["Propriété intellectuelle", "Les vidéos, supports et quiz sont protégés. Toute copie, enregistrement ou diffusion est interdite."],
      ["Avertissement", "Les formations ont un but éducatif. Elles ne constituent pas un conseil en investissement. Le trading comporte un risque de perte en capital."],
    ],
  },
  cgv: {
    title: "Conditions générales de vente",
    sections: [
      ["Prix", "Les prix sont indiqués en dinars algériens (DZD) et en euros pour les paiements internationaux, toutes taxes comprises le cas échéant."],
      ["Paiement", "Paiement par carte CIB, Edahabia ou carte internationale. L'accès est activé automatiquement après confirmation du paiement."],
      ["Accès", "L'accès à une formation achetée est valable à vie, sauf mention contraire sur la page de la formation."],
      ["Remboursement", "Une demande de remboursement peut être faite dans les 7 jours suivant l'achat si moins de 20 % du cours a été suivi."],
    ],
  },
  confidentialite: {
    title: "Politique de confidentialité",
    sections: [
      ["Données collectées", "Nom, adresse email, progression dans les cours et historique des paiements. Les données de carte bancaire ne transitent jamais par nos serveurs."],
      ["Utilisation", "Ces données servent uniquement à fournir l'accès aux formations, délivrer les certificats et assurer le support."],
      ["Vos droits", "Conformément à la loi 18-07, vous pouvez demander l'accès, la rectification ou la suppression de vos données en nous contactant."],
    ],
  },
} as const;

type DocKey = keyof typeof docs;

export function generateStaticParams() {
  return Object.keys(docs).map((doc) => ({ doc }));
}

export async function generateMetadata({ params }: PageProps<"/legal/[doc]">): Promise<Metadata> {
  const doc = docs[(await params).doc as DocKey];
  return doc ? { title: doc.title } : {};
}

export default async function LegalPage({ params }: PageProps<"/legal/[doc]">) {
  const doc = docs[(await params).doc as DocKey];
  if (!doc) notFound();

  return (
    <Container className="max-w-3xl py-12">
      <p className="text-sm font-medium text-amber-700">Version provisoire — à valider avant la mise en ligne.</p>
      <h1 className="mt-2 text-3xl font-bold">{doc.title}</h1>
      <div className="mt-8 space-y-6">
        {doc.sections.map(([title, text]) => (
          <section key={title}>
            <h2 className="text-lg font-semibold">{title}</h2>
            <p className="mt-2 leading-relaxed text-muted-foreground">{text}</p>
          </section>
        ))}
      </div>
    </Container>
  );
}
