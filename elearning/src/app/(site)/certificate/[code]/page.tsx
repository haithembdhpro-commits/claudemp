import type { Metadata } from "next";
import { Award, BadgeCheck, XCircle } from "lucide-react";
import { ButtonLink } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Container } from "@/components/ui/container";
import { getAllCertificateCodes, getCertificate } from "@/lib/data";
import { formatDate } from "@/lib/utils";

export function generateStaticParams() {
  return getAllCertificateCodes().map((code) => ({ code }));
}

export const metadata: Metadata = { title: "Vérification de certificat" };

export default async function CertificatePage({ params }: PageProps<"/certificate/[code]">) {
  const { code } = await params;
  const found = getCertificate(code);

  return (
    <Container className="max-w-2xl py-16">
      {found ? (
        <Card className="overflow-hidden text-center">
          <div className="flex items-center justify-center gap-2 bg-accent/15 py-3 text-sm font-medium text-emerald-800">
            <BadgeCheck className="size-4" aria-hidden /> Certificat authentique
          </div>
          <div className="space-y-4 p-8 sm:p-12">
            <Award className="mx-auto size-12 text-primary" aria-hidden />
            <p className="text-sm uppercase tracking-widest text-muted-foreground">Certificat de réussite</p>
            <p className="text-3xl font-bold">{found.cert.userName}</p>
            <p className="text-muted-foreground">a terminé avec succès la formation</p>
            <p className="text-xl font-semibold text-primary">{found.course.title}</p>
            <div className="flex flex-wrap justify-center gap-6 border-t border-border pt-6 text-sm text-muted-foreground">
              <span>Délivré le {formatDate(found.cert.issuedAt)}</span>
              <span className="font-mono">{found.cert.code}</span>
            </div>
          </div>
        </Card>
      ) : (
        <Card className="space-y-4 p-10 text-center">
          <XCircle className="mx-auto size-12 text-danger" aria-hidden />
          <h1 className="text-xl font-bold">Certificat introuvable</h1>
          <p className="text-muted-foreground">Le code <span className="font-mono">{code}</span> ne correspond à aucun certificat délivré.</p>
          <ButtonLink href="/" variant="outline">Retour à l&apos;accueil</ButtonLink>
        </Card>
      )}
    </Container>
  );
}
