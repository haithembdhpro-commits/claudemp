import type { Metadata } from "next";
import { CreditCard, TrendingUp, Trophy, Users } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { getAdminStats, getCourses } from "@/lib/data";
import { courseLessons, formatDzd, levelLabel } from "@/lib/utils";

export const metadata: Metadata = { title: "Administration", robots: { index: false } };

const dateTime = new Intl.DateTimeFormat("fr-FR", { dateStyle: "short", timeStyle: "short", timeZone: "Africa/Algiers" });

export default function AdminPage() {
  const stats = getAdminStats();
  const courses = getCourses();

  const kpis = [
    { label: "Chiffre d'affaires (30 j)", value: formatDzd(stats.revenueDzd), icon: TrendingUp },
    { label: "Ventes (30 j)", value: stats.sales30d.toString(), icon: CreditCard },
    { label: "Élèves", value: stats.students.toLocaleString("fr-FR"), icon: Users },
    { label: "Taux de complétion", value: `${stats.completionRate} %`, icon: Trophy },
  ];

  return (
    <div className="space-y-8 p-4 sm:p-8">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold">Vue d&apos;ensemble</h1>
          <p className="text-sm text-muted-foreground">Données de démonstration.</p>
        </div>
        <Button disabled title="Bientôt disponible">Nouvelle formation</Button>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {kpis.map(({ label, value, icon: Icon }) => (
          <Card key={label} className="p-5">
            <div className="flex items-center justify-between text-muted-foreground">
              <p className="text-sm">{label}</p>
              <Icon className="size-4" aria-hidden />
            </div>
            <p className="mt-2 text-2xl font-bold">{value}</p>
          </Card>
        ))}
      </div>

      <section id="cours" className="scroll-mt-6">
        <h2 className="mb-3 text-lg font-semibold">Formations</h2>
        <Card className="overflow-x-auto">
          <table className="w-full min-w-[560px] text-sm">
            <thead className="border-b border-border bg-muted/50 text-start text-xs uppercase text-muted-foreground">
              <tr>
                <th className="px-4 py-3 text-start font-medium">Titre</th>
                <th className="px-4 py-3 text-start font-medium">Niveau</th>
                <th className="px-4 py-3 text-end font-medium">Leçons</th>
                <th className="px-4 py-3 text-end font-medium">Élèves</th>
                <th className="px-4 py-3 text-end font-medium">Prix</th>
                <th className="px-4 py-3 text-start font-medium">Statut</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {courses.map((c) => (
                <tr key={c.id}>
                  <td className="px-4 py-3 font-medium">{c.title}</td>
                  <td className="px-4 py-3">{levelLabel[c.level]}</td>
                  <td className="px-4 py-3 text-end">{courseLessons(c).length}</td>
                  <td className="px-4 py-3 text-end">{c.students.toLocaleString("fr-FR")}</td>
                  <td className="px-4 py-3 text-end">{formatDzd(c.priceDzd)}</td>
                  <td className="px-4 py-3"><Badge tone="accent">Publié</Badge></td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      </section>

      <section id="ventes" className="scroll-mt-6">
        <h2 className="mb-3 text-lg font-semibold">Dernières ventes</h2>
        <Card className="overflow-x-auto">
          <table className="w-full min-w-[560px] text-sm">
            <thead className="border-b border-border bg-muted/50 text-xs uppercase text-muted-foreground">
              <tr>
                <th className="px-4 py-3 text-start font-medium">Élève</th>
                <th className="px-4 py-3 text-start font-medium">Formation</th>
                <th className="px-4 py-3 text-start font-medium">Moyen</th>
                <th className="px-4 py-3 text-end font-medium">Montant</th>
                <th className="px-4 py-3 text-end font-medium">Date</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {stats.recentSales.map((s) => (
                <tr key={s.id}>
                  <td className="px-4 py-3 font-medium">{s.student}</td>
                  <td className="px-4 py-3">{s.course}</td>
                  <td className="px-4 py-3"><Badge>{s.method}</Badge></td>
                  <td className="px-4 py-3 text-end">{formatDzd(s.amountDzd)}</td>
                  <td className="px-4 py-3 text-end text-muted-foreground">{dateTime.format(new Date(s.date))}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      </section>

      <section id="eleves" className="scroll-mt-6">
        <h2 className="mb-3 text-lg font-semibold">Élèves</h2>
        <Card className="p-6 text-sm text-muted-foreground">
          La liste des élèves, l&apos;inscription manuelle (paiement CCP/BaridiMob) et l&apos;export CSV arriveront avec la base Supabase.
        </Card>
      </section>
    </div>
  );
}
